<?php
declare(strict_types=1);

/**
 * Posts one reel to the Facebook Page (run by poster.php, never in a web request):
 * start → upload bytes (live %) → finish (public, same caption parts as Instagram, Follow line with the FB Page name) → wait until ready → link.
 * Progress goes to clips.fb_progress / fb_step. Instagram state (ig_*) is never touched here.
 * Double-post guard: a video left by an earlier try is checked first (already published = done,
 * publish already asked = only wait for it).
 */
class FbPoster
{
    private const MIN_SEC = 3, MAX_SEC = 90;   // Facebook Reels length
    private const MAX_WAIT = 600;              // seconds to wait for Facebook's processing

    public static function run(array $clip): void
    {
        $id = (int)$clip['id'];
        $caption = IgCaption::forPost((string)($clip['ig_caption'] ?? ''), 'fb');   // T7: ticked parts + FB Page name
        if ($why = IgCaption::problem($caption)) throw new RuntimeException($why);
        if ($clip['start_sec'] !== null && $clip['end_sec'] !== null) {
            $len = (float)$clip['end_sec'] - (float)$clip['start_sec'];
            if ($len < self::MIN_SEC || $len > self::MAX_SEC + 0.5) {
                throw new RuntimeException(sprintf('Facebook reels must be %d–%d seconds long; this one is %d s.', self::MIN_SEC, self::MAX_SEC, (int)round($len)));
            }
        }
        $file = Poster::file((string)$clip['file']);

        Poster::step($id, 3, 'Checking your Facebook Page…', 'fb');
        $vid = (string)($clip['fb_video_id'] ?? '');
        $state = $vid !== '' ? self::stateOf($vid) : '';
        if ($state === 'posted') { self::done($id, $vid); return; }   // posted already: never post twice
        if ($state !== 'publishing') {
            Poster::step($id, 8, 'Creating the reel on Facebook…', 'fb');
            $vid = Facebook::start();
            db()->prepare('UPDATE clips SET fb_video_id=? WHERE id=?')->execute([$vid, $id]);
            self::upload($id, $vid, $file);
            Poster::step($id, 72, 'Publishing on Facebook…', 'fb');
            Facebook::finish($vid, $caption);
        }
        self::waitReady($id, $vid);
        self::done($id, $vid);
    }

    /** 'posted' / 'publishing' (finish was already sent) / '' (start fresh). */
    private static function stateOf(string $vid): string
    {
        try { $s = Facebook::status($vid); } catch (Throwable) { return ''; }   // gone: start fresh
        $pub = (string)($s['publishing_phase']['status'] ?? '');
        if (in_array($pub, ['', 'not_started', 'error'], true)) return '';
        return ($s['video_status'] ?? '') === 'ready' && $pub === 'complete' ? 'posted' : 'publishing';
    }

    /** 10 → 70 %: bytes to rupload. A dropped connection is tried once more. */
    private static function upload(int $id, string $vid, string $file): void
    {
        $last = 0.0;
        $onProgress = function (int $sent, int $size) use ($id, &$last) {
            if (microtime(true) - $last < 0.5 && $sent < $size) return;
            $last = microtime(true);
            Poster::step($id, 10 + (int)round(60 * $sent / max(1, $size)),
                sprintf('Uploading %.1f / %.1f MB', $sent / 1048576, $size / 1048576), 'fb');
        };
        try { Facebook::upload($vid, $file, 0, $onProgress); }
        catch (IgNetworkError) { Facebook::upload($vid, $file, 0, $onProgress); }
        Poster::step($id, 70, 'Uploaded to Facebook', 'fb');
    }

    /** 74 → 96 %: poll until the reel is processed and published. */
    private static function waitReady(int $id, string $vid): void
    {
        $t0 = time(); $every = max(2, (int)cfg('ig_poll_sec'));
        while (true) {
            $s = Facebook::status($vid);
            $vs = (string)($s['video_status'] ?? '');
            $pub = (string)($s['publishing_phase']['status'] ?? '');
            foreach (['uploading_phase', 'processing_phase', 'publishing_phase'] as $ph) {
                if (($s[$ph]['status'] ?? '') === 'error' || $vs === 'error') {
                    $why = (string)($s[$ph]['errors'][0]['message'] ?? $s[$ph]['error']['message'] ?? '');
                    throw new RuntimeException('Facebook could not process the video' . ($why !== '' ? ": $why" : '. Click Post again.'));
                }
            }
            if ($vs === 'expired') throw new RuntimeException('Facebook upload expired. Click Post again.');
            if ($vs === 'ready' && in_array($pub, ['', 'complete'], true)) { Poster::step($id, 96, 'Reel is live on Facebook', 'fb'); return; }
            $waited = time() - $t0;
            if ($waited > self::MAX_WAIT) throw new RuntimeException('Facebook is taking too long to process the video. Click Post again later.');
            Poster::step($id, 74 + (int)round(22 * (1 - exp(-$waited / 40))), "Facebook is processing the video… {$waited} s", 'fb');
            sleep($every);
        }
    }

    private static function done(int $id, string $vid): void
    {
        Poster::step($id, 98, 'Getting the post link…', 'fb');
        try { $link = Facebook::permalink($vid); } catch (Throwable) { $link = 'https://www.facebook.com/reel/' . rawurlencode($vid); }
        db()->prepare("UPDATE clips SET fb_status='posted', fb_progress=100, fb_step='Posted', fb_error=NULL,
                       fb_permalink=?, fb_posted_at=NOW() WHERE id=?")->execute([$link, $id]);
        if (PHP_SAPI === 'cli') echo "[reel $id fb] posted $link\n";
    }
}
