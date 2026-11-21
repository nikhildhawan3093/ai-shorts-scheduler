<?php
declare(strict_types=1);

/**
 * Posts one reel to Instagram (run by poster.php, never in a web request):
 * check account + 24 h limit → create container → upload bytes (live %) → wait for processing → publish → link.
 * Progress goes to clips.ig_progress / ig_step so the dashboard shows it live.
 * Double-post guard: a container left by an earlier try is checked first (PUBLISHED = already posted).
 */
class Poster
{
    private const MAX_BYTES = 300 * 1048576;   // Instagram Reels limit
    private const MAX_WAIT = 600;              // seconds to wait for Instagram's processing

    public static function run(array $clip): void
    {
        $id = (int)$clip['id'];
        $file = self::file((string)$clip['file']);
        $caption = IgCaption::forPost((string)($clip['ig_caption'] ?? ''), 'ig');   // T7: ticked parts + IG name
        if ($why = IgCaption::problem($caption)) throw new RuntimeException($why);

        self::step($id, 3, 'Checking your Instagram account…');
        Instagram::renewIfDue();
        if (!Instagram::connected()) throw new RuntimeException('Instagram is not connected. Open "Instagram account" and save your token.');

        // an earlier try of this reel left a container behind
        $cid = (string)($clip['ig_container_id'] ?? '');
        $state = $cid !== '' ? self::stateOf($cid) : '';
        if ($state === 'PUBLISHED') { self::done($id, '', ''); return; }   // posted already: never post twice
        if ($state !== 'FINISHED') {
            self::checkLimit();
            $cid = self::viaTunnel($id, $file, $caption);
            if ($cid === '') {   // tunnel off or could not start: direct upload (+ automatic retry in poster.php)
                self::step($id, 8, 'Creating the reel on Instagram…');
                $cid = self::newContainer($id, $caption);
                self::upload($id, $cid, $file);
                self::waitReady($id, $cid);
            }
        }

        self::step($id, 94, 'Publishing…');
        $mediaId = Instagram::publish($cid);
        db()->prepare('UPDATE clips SET ig_media_id=? WHERE id=?')->execute([$mediaId, $id]);
        self::step($id, 98, 'Getting the post link…');
        try { $link = Instagram::permalink($mediaId); } catch (Throwable) { $link = ''; }   // posted anyway
        self::done($id, $mediaId, $link);
    }

    /** Absolute path of the reel, only inside storage_dir. */
    public static function file(string $url): string
    {
        $rel = substr($url, strlen((string)cfg('storage_url')) + 1);
        $real = realpath(cfg('storage_dir') . '/' . $rel);
        $root = realpath((string)cfg('storage_dir'));
        if (!$real || !$root || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || !is_file($real)) {
            throw new RuntimeException('The reel video file is missing. Make the reels again.');
        }
        if (filesize($real) > self::MAX_BYTES) throw new RuntimeException('Video is bigger than 300 MB (Instagram limit)');
        return $real;
    }

    /**
     * T3d: Instagram fetches the reel itself (video_url, full quality) through a one-time tunnel that serves
     * only this reel and is off again as soon as Instagram says FINISHED / ERROR. '' = tunnel not used.
     */
    private static function viaTunnel(int $id, string $file, string $caption): string
    {
        if (!cfg('ig_tunnel')) return '';
        self::step($id, 6, 'Opening a one-time link for Instagram…');
        try { $t = Tunnel::open($file); }
        catch (Throwable $e) {
            if (PHP_SAPI === 'cli') echo "[reel $id ig] {$e->getMessage()} → direct upload\n";
            return '';
        }
        try {
            self::step($id, 15, 'Link on. Creating the reel on Instagram…');
            $cid = self::newContainer($id, $caption, $t->url);
            self::waitReady($id, $cid, false);
        } finally {
            $t->close();
        }
        self::step($id, 93, 'Link off. Video ready on Instagram');
        return $cid;
    }

    private static function newContainer(int $id, string $caption, string $videoUrl = ''): string
    {
        $cid = Instagram::createContainer($caption, $videoUrl);
        db()->prepare('UPDATE clips SET ig_container_id=? WHERE id=?')->execute([$cid, $id]);
        return $cid;
    }

    /** Old container state. A container whose upload failed on Meta's side can still say FINISHED: start fresh then. */
    private static function stateOf(string $cid): string
    {
        try { $s = Instagram::containerStatus($cid); }
        catch (Throwable) {
            // video_url containers (tunnel) have no video_status: ask again without it
            try { $s = Instagram::containerStatus($cid, false); }
            catch (Throwable) { return ''; }   // old container gone / expired: start fresh
        }
        if (($s['video_status']['uploading_phase']['status'] ?? '') === 'error') return '';
        return (string)($s['status_code'] ?? '');
    }

    /** Instagram allows 50 API posts per 24 h (content_publishing_limit). Our own count is the fallback. */
    private static function checkLimit(): void
    {
        try { [$used, $total] = Instagram::quota(); }
        catch (IgNetworkError $e) { throw $e; }
        catch (Throwable) {
            $used = (int)db()->query("SELECT COUNT(*) FROM clips WHERE ig_status='posted' AND ig_posted_at > NOW() - INTERVAL 1 DAY")->fetchColumn();
            $total = 50;
        }
        if ($used >= $total) throw new IgLimitReached("Instagram allows $total posts in 24 hours and the limit is reached. Try again later.");
    }

    /** 10 → 70 %: bytes to rupload. A dropped connection resumes once from where Instagram stopped. */
    private static function upload(int $id, string $cid, string $file): void
    {
        $last = 0.0;
        $onProgress = function (int $sent, int $size) use ($id, &$last) {
            if (microtime(true) - $last < 0.5 && $sent < $size) return;
            $last = microtime(true);
            self::step($id, 10 + (int)round(60 * $sent / max(1, $size)),
                sprintf('Uploading %.1f / %.1f MB', $sent / 1048576, $size / 1048576));
        };
        try {
            Instagram::upload($cid, $file, 0, $onProgress);
        } catch (IgNetworkError) {
            $vs = Instagram::containerStatus($cid)['video_status']['uploading_phase'] ?? [];
            Instagram::upload($cid, $file, (int)($vs['bytes_transferred'] ?? 0), $onProgress);
        }
        self::step($id, 70, 'Uploaded. Instagram is processing the video…');
    }

    /** 70 → 92 % (tunnel: 15 → 92 %): poll status_code until FINISHED. */
    private static function waitReady(int $id, string $cid, bool $resumable = true): void
    {
        $t0 = time(); $every = max(2, (int)cfg('ig_poll_sec'));
        [$from, $what] = $resumable ? [70, 'Instagram is processing the video…'] : [15, 'Link on. Instagram is fetching the video…'];
        while (true) {
            $s = Instagram::containerStatus($cid, $resumable);
            $code = (string)($s['status_code'] ?? '');
            // Meta lost the uploaded file (FILE_NOT_FOUND): do not wait or publish, try again later
            if (($s['video_status']['uploading_phase']['status'] ?? '') === 'error') throw new IgBusy('Instagram lost the uploaded video on its side.');
            if ($code === 'FINISHED') { self::step($id, 92, 'Video ready on Instagram'); return; }
            if ($code === 'ERROR') throw new IgBusy('Instagram could not process the video' . (!empty($s['status']) ? ': ' . $s['status'] : '.'));
            if ($code === 'EXPIRED') throw new RuntimeException('Instagram upload expired. Click Post again.');
            $waited = time() - $t0;
            if ($waited > self::MAX_WAIT) throw new RuntimeException('Instagram is taking too long to process the video. Click Post again later.');
            // creep towards 92 % (most reels finish in 10–60 s)
            self::step($id, $from + (int)round((92 - $from) * (1 - exp(-$waited / 40))), "$what {$waited} s");
            sleep($every);
        }
    }

    private static function done(int $id, string $mediaId, string $link): void
    {
        db()->prepare("UPDATE clips SET ig_status='posted', ig_progress=100, ig_step='Posted', ig_error=NULL,
                       ig_media_id=COALESCE(NULLIF(?, ''), ig_media_id), ig_permalink=NULLIF(?, ''), ig_posted_at=NOW() WHERE id=?")
            ->execute([$mediaId, $link, $id]);
        if (PHP_SAPI === 'cli') echo "[reel $id ig] posted $link\n";
    }

    /** Live step + % of one platform ('ig' / 'fb'); stops the post if the reel was deleted meanwhile. */
    public static function step(int $id, int $pct, string $msg, string $p = 'ig'): void
    {
        $p = $p === 'fb' ? 'fb' : 'ig';
        db()->prepare("UPDATE clips SET {$p}_progress=?, {$p}_step=? WHERE id=?")->execute([max(0, min(100, $pct)), mb_substr($msg, 0, 250), $id]);
        $st = db()->prepare('SELECT 1 FROM clips WHERE id=?');
        $st->execute([$id]);
        if (!$st->fetchColumn()) throw new JobCancelled("Reel $id was deleted");
        if (PHP_SAPI === 'cli') echo "[reel $id $p] $pct% $msg\n";
    }
}
