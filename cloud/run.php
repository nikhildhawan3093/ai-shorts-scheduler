<?php
/**
 * T8: GitHub Actions poster. Posts every reel side that is due (or due within 5 min: waits for it) with the
 * SAME Poster (Instagram, video_url through a runner-side quick tunnel) and FbPoster (Facebook) as the
 * laptop, using the runner's MySQL as a scratch database. Each result goes to state.json at once.
 * Instagram busy on Meta's side → tried again by a later run (CloudState::RETRY_MIN).   php cloud/run.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit("CLI only\n");
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/CloudState.php';
set_time_limit(0);

// 2026-11-21: GitHub's 15-min timer ran only 2× in 11 h → reels hours late. Now ONE run stays alive and sleeps until
// each reel's time (public repo = free minutes); before GitHub's 6-h job limit it starts the next run itself.
const MAX_RUN = 320 * 60;  // no new post after this (job timeout 355 min leaves room for a slow Instagram post)
const NAP = 60;            // re-read the laptop's plan (cancel / new reels) at least this often while waiting

/** settings.enc → settings table (tokens exist only in this run's scratch database). */
function load_settings(): void
{
    $key = base64_decode((string)getenv('SCHED_KEY'));
    $e = json_decode((string)@file_get_contents(CloudState::root() . '/settings.enc'), true);
    if (strlen($key) !== 32 || !is_array($e)) throw new RuntimeException('settings.enc or the SCHED_KEY secret is missing');
    $plain = openssl_decrypt(base64_decode($e['data']), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, base64_decode($e['iv']), base64_decode($e['tag']));
    if ($plain === false) throw new RuntimeException('settings.enc could not be opened (SCHED_KEY changed?)');
    foreach ((array)json_decode($plain, true) as $k => $v) if ($v !== null) Brand::set((string)$k, (string)$v);
}

/** Reel file from the release (once per run) + its row in the scratch database. */
function prepare(array $it, array $state): array
{
    $dir = cfg('storage_dir');
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = (string)$it['asset'];
    if (!preg_match('/^r\d+_[0-9a-f]{12}\.mp4$/', $name)) throw new RuntimeException('Bad reel name in the plan');
    if (!is_file("$dir/$name")) {
        exec('gh release download sched -p ' . escapeshellarg($name) . ' -D ' . escapeshellarg($dir) . ' --clobber 2>&1', $o, $code);
        if ($code !== 0) throw new RuntimeException('Reel file is missing on GitHub (' . mb_substr(implode(' ', $o), 0, 150) . ')');
    }
    $s = $state['items'][$name] ?? [];
    db()->prepare("REPLACE INTO clips (id, job_id, file, ig_caption, start_sec, end_sec, ig_status, fb_status, ig_container_id, fb_video_id)
                   VALUES (?, 1, ?, ?, ?, ?, 'posting', 'posting', NULLIF(?, ''), NULLIF(?, ''))")
        ->execute([(int)$it['id'], "storage/$name", (string)$it['caption'], $it['start_sec'], $it['end_sec'],
                   (string)($s['ig']['container_id'] ?? ''), (string)($s['fb']['video_id'] ?? '')]);
    $st = db()->prepare('SELECT * FROM clips WHERE id=?');
    $st->execute([(int)$it['id']]);
    return $st->fetch();
}

function post_side(array $it, string $p, array &$state): void
{
    $asset = (string)$it['asset'];
    $old = (array)($state['items'][$asset][$p] ?? []);
    $state['items'][$asset]['plan_at'] = (int)$it['at'];   // keeps the gap for the next reel (CloudState::dueAt)
    $state['items'][$asset]['job'] = (int)($it['job'] ?? 0);
    CloudState::save($state, $asset, $p, ['status' => 'posting', 'step' => 'GitHub is posting…'] + $old);
    $row = null;
    try {
        $row = prepare($it, $state);
        $p === 'ig' ? Poster::run($row) : FbPoster::run($row);
        $row = db()->query('SELECT * FROM clips WHERE id=' . (int)$it['id'])->fetch();
        CloudState::save($state, $asset, $p, ['status' => 'posted', 'done_at' => time(),
            'permalink' => (string)($row["{$p}_permalink"] ?? ''),
            'media_id' => (string)($p === 'ig' ? $row['ig_media_id'] : $row['fb_video_id'])] + ids($row));
        echo "[reel {$it['id']} $p] posted\n";
    } catch (Throwable $e) {
        $row = $row ? (db()->query('SELECT * FROM clips WHERE id=' . (int)$it['id'])->fetch() ?: $row) : null;
        $msg = mb_substr($e->getMessage(), 0, 400);
        $tries = (int)($old['tries'] ?? 0) + 1;
        if ($e instanceof IgBusy && $p === 'ig' && $tries <= count(CloudState::RETRY_MIN)) {
            $min = CloudState::RETRY_MIN[$tries - 1];
            CloudState::save($state, $asset, $p, ['status' => 'retry', 'tries' => $tries, 'retry_at' => time() + 60 * $min,
                'step' => "Instagram is busy. GitHub tries again in about $min min (try " . ($tries + 1) . ')'] + ($row ? ids($row) : []));
        } else {
            CloudState::save($state, $asset, $p, ['status' => 'failed', 'error' => $msg] + ($row ? ids($row) : []));
        }
        echo "[reel {$it['id']} $p] $msg\n";
    }
}

/** Container / video ids: the next try checks them first, so a reel is never posted twice. */
function ids(array $row): array
{
    return ['container_id' => (string)($row['ig_container_id'] ?? ''), 'video_id' => (string)($row['fb_video_id'] ?? '')];
}

load_settings();
db()->exec("INSERT IGNORE INTO jobs (id, type, title, status, input) VALUES (1, 'reels', 'cloud', 'done', '{}')");
$t0 = time();
$left = false;   // reels still planned when this run stops → start the next run
while (true) {
    CloudState::pull();
    $plan = CloudState::plan();
    $state = CloudState::state();
    $wait = CloudState::wait($plan, $state, time());
    if ($wait === null) break;                                   // nothing planned any more
    if (time() - $t0 >= MAX_RUN) { $left = true; break; }
    $next = CloudState::next($plan, $state, time());
    if ($next) {
        post_side($next[0], $next[1], $state);
        if (CloudState::finished($next[0], $state)) CloudState::dropAsset((string)$next[0]['asset']);   // T8b
        continue;
    }
    if ($wait > 0) sleep(max(5, min(NAP, $wait)));
}
CloudState::squash();   // T8b: public repo keeps no old history
if ($left) {
    exec('gh workflow run post.yml --ref main 2>&1', $o, $code);
    echo $code === 0 ? "Next run started (reels still planned).\n" : 'Could not start the next run: ' . implode(' ', $o) . "\n";
}
echo "Run finished.\n";
