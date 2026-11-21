<?php
declare(strict_types=1);

/**
 * T8 cloud side: schedule.json (written by the laptop) + state.json (written only here, keyed by the
 * reel's asset name), and which side of which reel is due. state.json is committed + pushed after every
 * change, so a crash never loses a container / video id (double-post guard on the next run).
 */
class CloudState
{
    public const RETRY_MIN = [2, 5, 10, 20, 30, 60];   // Instagram busy on Meta's side: try again after … (like poster.php)
    public const EARLY = 60;                             // a reel counts as due this many seconds before its time
    public const KEEP_SEC = 48 * 3600;                   // T8b: a finished reel's state.json entry is kept this long
                                                         // after the laptop took it out of the plan (public repo)

    public static function root(): string { return dirname(__DIR__); }

    public static function plan(): array
    {
        return (array)(json_decode((string)@file_get_contents(self::root() . '/schedule.json'), true)['items'] ?? []);
    }

    public static function state(): array
    {
        $j = json_decode((string)@file_get_contents(self::root() . '/state.json'), true);
        return is_array($j) && is_array($j['items'] ?? null) ? $j : ['items' => []];
    }

    /**
     * When a reel may really go out. On time normally; but when the reel before it went out late, this one keeps
     * the user's gap after it (no burst of overdue reels at once — 2026-11-21: 7 reels in 10 min after GitHub skipped
     * its timer). "Reel before" = the one with the nearest earlier planned time, in the plan or already in state.json.
     */
    public static function dueAt(array $plan, array $state, array $item): int
    {
        $at = (int)$item['at'];
        $job = (int)($item['job'] ?? 0);
        if ($job === 0) return $at;   // plan from older laptop code: no job → on time only
        $prevAt = null; $prevAsset = '';
        // only reels of the SAME job (one planned batch): an old late batch never shifts a new one
        $cands = array_map(fn($p) => [(int)$p['at'], (string)$p['asset'], (int)($p['job'] ?? 0)], $plan);
        foreach ((array)$state['items'] as $a => $s) if (isset($s['plan_at'])) $cands[] = [(int)$s['plan_at'], (string)$a, (int)($s['job'] ?? 0)];
        foreach ($cands as [$pa, $asset, $j]) {
            if ($j === $job && $pa < $at && ($prevAt === null || $pa > $prevAt)) { $prevAt = $pa; $prevAsset = $asset; }
        }
        if ($prevAt === null) return $at;
        $prevItem = null;
        foreach ($plan as $p) if ((string)$p['asset'] === $prevAsset) $prevItem = $p;
        $gap = $at - $prevAt;
        $done = self::doneAt($state, $prevAsset);
        if ($done !== null) return max($at, $done + $gap);
        // the reel before is not out yet → this one goes after it (never overtakes it), still one gap later
        return $prevItem ? max($at, self::dueAt($plan, $state, $prevItem) + $gap) : $at;
    }

    /** When the last side of this reel finished (posted / failed), or null while it is not finished. */
    private static function doneAt(array $state, string $asset): ?int
    {
        $t = null;
        foreach ((array)($state['items'][$asset] ?? []) as $x) {
            if (!is_array($x)) continue;   // plan_at
            if (!in_array((string)($x['status'] ?? ''), ['posted', 'failed'], true)) return null;
            $t = max($t ?? 0, (int)($x['done_at'] ?? $x['at'] ?? 0));
        }
        return $t;
    }

    /** Is this side finished (posted / failed) or waiting for a later retry? */
    public static function sideDue(array $plan, array $state, array $item, string $p, int $now): bool
    {
        $x = (array)($state['items'][$item['asset']][$p] ?? []);
        $s = (string)($x['status'] ?? '');
        if ($s === 'posted' || $s === 'failed') return false;
        if ($s === 'retry' && (int)($x['retry_at'] ?? 0) > $now) return false;
        return self::dueAt($plan, $state, $item) - self::EARLY <= $now;
    }

    /** [item, side] due now, or null. */
    public static function next(array $plan, array $state, int $now): ?array
    {
        foreach ($plan as $it) foreach ((array)$it['sides'] as $p) {
            if (in_array($p, ['ig', 'fb'], true) && self::sideDue($plan, $state, $it, $p, $now)) return [$it, $p];
        }
        return null;
    }

    /** Seconds until the next side is due (null = nothing left at all). */
    public static function wait(array $plan, array $state, int $now): ?int
    {
        $min = null;
        foreach ($plan as $it) foreach ((array)$it['sides'] as $p) {
            $x = (array)($state['items'][$it['asset']][$p] ?? []);
            $s = (string)($x['status'] ?? '');
            if ($s === 'posted' || $s === 'failed') continue;
            $t = max(self::dueAt($plan, $state, $it) - self::EARLY, $s === 'retry' ? (int)($x['retry_at'] ?? 0) : 0);
            $min = min($min ?? PHP_INT_MAX, max(0, $t - $now));
        }
        return $min;
    }

    /** Save one side's result and push it (rebases on top of the laptop's commits). Old plans are pruned. */
    public static function save(array &$state, string $asset, string $p, array $x): void
    {
        $state['items'][$asset][$p] = $x + ['at' => time()];
        $live = array_flip(array_column(self::plan(), 'asset'));
        foreach (array_keys($state['items']) as $a) {
            if (!isset($live[$a]) && time() - (int)max(array_column($state['items'][$a], 'at') ?: [0]) > self::KEEP_SEC) unset($state['items'][$a]);
        }
        $state['updated'] = time();
        file_put_contents(self::root() . '/state.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        $st = (string)($x['status'] ?? '');
        for ($i = 0; $i < 5; $i++) {
            self::git('add state.json');
            self::git('commit -q -m ' . escapeshellarg("State: $asset $p $st"));
            if (self::git('pull -q --rebase origin main') && self::git('push -q origin HEAD:main')) return;
            sleep(3);
        }
        echo "WARNING: could not push state.json\n";
    }

    /** Pull the laptop's latest plan (cancel / new reels) before the next post. */
    public static function pull(): void { self::git('pull -q --rebase origin main'); }

    /** T8b: every planned side of this reel is done (posted or failed) → its video is no longer needed. */
    public static function finished(array $item, array $state): bool
    {
        foreach ((array)$item['sides'] as $p) {
            if (!in_array((string)($state['items'][$item['asset']][$p]['status'] ?? ''), ['posted', 'failed'], true)) return false;
        }
        return true;
    }

    /** T8b: delete a finished reel's video from the public release at once (not waiting for the laptop). */
    public static function dropAsset(string $name): void
    {
        if (!preg_match('/^r\d+_[0-9a-f]{12}\.mp4$/', $name)) return;
        exec('gh release delete-asset sched ' . escapeshellarg($name) . ' -y 2>&1', $o, $code);
        @unlink(self::root() . "/cloud/work/storage/$name");
        echo $code === 0 ? "[$name] video removed from GitHub\n" : "[$name] could not remove the video: " . implode(' ', $o) . "\n";
    }

    /**
     * T8b: the repo is public, so old commits (old schedule.json captions, old state) must not stay in its
     * history. Replace the whole history by ONE commit with today's files. --force-with-lease: if the laptop
     * pushed meanwhile, nothing is overwritten (tried again at the end of the next run).
     */
    public static function squash(): void
    {
        $repo = (string)getenv('GITHUB_REPOSITORY');
        if ($repo === '') return;
        exec('gh api ' . escapeshellarg("repos/$repo/commits?per_page=2") . " --jq length 2>&1", $o, $code);
        if ($code !== 0 || (int)trim((string)end($o)) < 2) return;   // already a single commit
        if (!self::git('pull -q --rebase origin main')) return;
        exec('git -C ' . escapeshellarg(self::root()) . ' rev-parse HEAD', $h);
        $base = trim((string)($h[0] ?? ''));
        if (!preg_match('/^[0-9a-f]{40}$/', $base)) return;
        $ok = self::git('checkout -q --orphan fresh') && self::git('commit -q -m ' . escapeshellarg('Current files (old history removed)'))
            && self::git("push -q --force-with-lease=main:$base origin fresh:main");
        if ($ok) self::git('push -q -f origin fresh:refs/tags/sched');   // the release tag must not keep the old history
        self::git("checkout -q -f $base");
        echo $ok ? "History cleaned: the repo keeps only the current files.\n" : "History not cleaned this time (laptop pushed meanwhile).\n";
    }

    private static function git(string $args): bool
    {
        exec('git -C ' . escapeshellarg(self::root()) . " $args 2>&1", $out, $code);
        return $code === 0;
    }
}
