<?php
declare(strict_types=1);

/**
 * Instagram caption per clip, in the approved layout (PLAN.md):
 *   hook line + 2-3 lines (LLM)  /  Follow @page  /  Ignore tags: 5 hashtags (LLM)  /  credit disclaimer (code).
 * One LLM call per job for all clips. English or Roman Hinglish only (never Devanagari / Urdu script).
 * Without an LLM (or when it fails) a plain caption is built from the clip title + hashtags.
 */
class IgCaption
{
    private const MAX_LEN = 2200;    // Instagram caption limit
    public const IG_MAX_TAGS = 5;    // Instagram limit per post / reel since Dec 2025 (was 30)
    private const OTHER_APPS = '/^(yt|youtube|tiktok|shorts)|shorts$/i';   // tags of other apps don't belong on Instagram
    private const BASE_TAGS = ['#reels', '#viral', '#trending', '#explore', '#instagood', '#reelsinstagram'];

    /** Video metadata worth keeping from yt-dlp -J (saved as meta.json in the job folder). */
    public static function meta(?array $info, string $url = ''): array
    {
        return ['url' => $url, 'title' => (string)($info['title'] ?? ''), 'channel' => (string)($info['channel'] ?? ''),
                'description' => (string)($info['description'] ?? ''), 'tags' => array_values($info['tags'] ?? [])];
    }

    /**
     * @param list<array{start:float,end:float,title:string,hashtags?:string}> $clips
     * @return list<string> one caption per clip, same order
     */
    public static function forClips(array $clips, array $segments, array $meta): array
    {
        $ai = [];
        if ($clips && AI::hasLlm()) {
            try { $ai = self::ask(array_values($clips), $segments, $meta); }
            catch (JobCancelled $e) { throw $e; }
            catch (Throwable $e) { $ai = []; }                       // fall back to plain captions
        }
        $out = [];
        foreach (array_values($clips) as $k => $c) $out[] = self::build($ai['clips'][$k] ?? null, $c, $meta, $ai['video_title'] ?? '');
        return $out;
    }

    /** @return array{clips: array<int, array{text:string,tags:string}>, video_title: string} */
    private static function ask(array $clips, array $segments, array $meta): array
    {
        $text = '';
        foreach ($clips as $k => $c) {
            $said = [];
            foreach ($segments as $s) if ($s['end'] > $c['start'] && $s['start'] < $c['end']) $said[] = trim($s['text']);
            $text .= '### Clip ' . ($k + 1) . " (title: {$c['title']})\n" . mb_substr(implode(' ', $said), 0, 1500) . "\n\n";
        }
        $desc = mb_substr(trim(preg_replace(['~https?://\S+~', '/\n{2,}/'], ['', "\n"], $meta['description'] ?? '')), 0, 600);
        $tags = implode(', ', array_slice($meta['tags'] ?? [], 0, 15));
        $user = "Video: \"{$meta['title']}\" by {$meta['channel']}.\n" . ($desc !== '' ? "Video description:\n$desc\n" : '') .
            ($tags !== '' ? "Video tags: $tags\n" : '') . "\nWhat is said in each clip:\n\n$text" .
            "Write an Instagram Reels caption for each clip.\n" .
            "- text: 3-4 short lines separated by \\n. Line 1 is a scroll-stopping hook about what THIS clip shows (a " .
            "question, a bold or funny line); then 2-3 lines about the clip (what happens, why it is worth watching). " .
            "Use emojis that fit the tone. Under 400 characters. Must make sense to someone who has not seen the video.\n" .
            "- tags: exactly 5 hashtags (Instagram allows only 5), best first: the person / show name, the exact clip " .
            "topic, the niche; at most 1 broad tag. No spaces inside a tag. No YouTube / TikTok / Shorts tags.\n" .
            "- video_title: the video title written in English or Roman script (keep it as is if it already is).\n" .
            "Language: English or Roman-script Hinglish ONLY (Roman Hinglish when the speech is Hindi). NO Devanagari or " .
            "Urdu script anywhere. Never write 'part N', timestamps or 'in this clip'.\n" .
            'Return: {"video_title":"","clips":[{"clip":number,"text":"","tags":""}]}';
        $res = AI::chatJson('You write viral Instagram Reels captions. Reply only with JSON.', $user, 0.6);

        $out = ['clips' => [], 'video_title' => ClipAI::roman((string)($res['video_title'] ?? ''))];
        $list = array_values(array_filter((array)($res['clips'] ?? []), 'is_array'));
        $byPos = count($list) === count($clips);   // one answer per clip: keep the order (models number clips unreliably)
        foreach ($list as $pos => $r) {
            $k = $byPos ? $pos : (int)($r['clip'] ?? 0) - 1;
            if (isset($clips[$k])) $out['clips'][$k] = ['text' => ClipAI::roman((string)($r['text'] ?? '')),
                                                        'tags' => (string)($r['tags'] ?? '')];
        }
        return $out;
    }

    private static function build(?array $ai, array $c, array $meta, string $romanTitle): string
    {
        $text = trim($ai['text'] ?? '') ?: trim($c['title']) . "\n\nWatch till the end 👀";
        $page = Brand::page();
        // Instagram counts every # in the caption (max 5): hashtags live only in the tag block, never in the hook text
        $text = preg_replace('/#(?=[\p{L}\p{N}_])/u', '', $text);
        $rest = [$page !== '' ? "Follow @$page for more 🔥" : 'Follow for more 🔥',
                 "Ignore tags:\n" . self::tags(($ai['tags'] ?? '') . ' ' . ($c['hashtags'] ?? ''), $meta, self::IG_MAX_TAGS)];

        $title = trim($meta['title'] ?? '');
        if ($title !== '' && ClipAI::roman($title) === '') $title = $romanTitle;   // Devanagari title → Roman version
        $channel = trim($meta['channel'] ?? '');
        if ($title !== '' || $channel !== '') {
            $rest[] = 'Disclaimer: This clip is taken from ' . ($title !== '' ? "'$title'" : 'a video') .
                ($channel !== '' ? " by $channel" : '') . ".\nAll credit goes to the original creator and owner. " .
                'Used for informational purposes only. DM for removal.';
        }
        // over 2200: shorten the hook text, never the tags or the credit line
        $tail = "\n\n" . implode("\n\n", $rest);
        while ($text !== '' && self::length($text . $tail) > self::MAX_LEN) $text = rtrim(mb_substr($text, 0, -10));
        return $text . $tail;
    }

    /** T7: caption parts the user posts (one-time setting in "Your page"); all on by default. */
    public const PARTS = ['main', 'follow', 'tags', 'disclaimer'];

    /** @return list<string> ticked parts */
    public static function parts(): array
    {
        $v = Brand::get('caption_parts');
        return $v === null ? self::PARTS : array_values(array_intersect(self::PARTS, explode(',', $v)));
    }

    public static function saveParts(array $ticked): void
    {
        Brand::set('caption_parts', implode(',', array_intersect(self::PARTS, $ticked)));
    }

    /**
     * Text actually posted: unticked parts are left out, the Follow line names the page of that platform
     * ('ig' = Instagram page name, 'fb' = Facebook Page name). The text of each part is never changed.
     */
    public static function forPost(string $caption, string $platform): string
    {
        $on = array_flip(self::parts());
        $name = $platform === 'fb' ? trim((string)Brand::get('fb_page_name')) : Brand::page();
        $keep = [];
        foreach (preg_split('/\n{2,}/u', trim(str_replace("\r", '', $caption))) as $block) {
            $part = self::partOf($block);
            if (!isset($on[$part])) continue;
            if ($part === 'follow') $block = self::followLine($platform, $name);
            $keep[] = $block;
        }
        return implode("\n\n", $keep);
    }

    /** Instagram: "@name". Facebook: Page name + Page link (a URL is always clickable in a FB reel description). */
    private static function followLine(string $platform, string $name): string
    {
        if ($platform !== 'fb') return $name !== '' ? "Follow @$name for more 🔥" : 'Follow for more 🔥';
        $id = (string)Brand::get('fb_page_id');
        $line = $name !== '' ? "Follow $name for more 🔥" : 'Follow for more 🔥';
        return ctype_digit($id) ? "$line\n👉 https://www.facebook.com/$id" : $line;
    }

    private static function partOf(string $block): string
    {
        if (!str_contains($block, "\n") && preg_match('/^Follow\b.*\bfor more\b/iu', $block)) return 'follow';
        if (preg_match('/^Ignore tags:/iu', $block) || preg_match('/^(#[\p{L}\p{N}_]+\s*)+$/u', $block)) return 'tags';
        if (preg_match('/^Disclaimer:/iu', $block)) return 'disclaimer';
        return 'main';
    }

    /** Length the way Instagram counts it (UTF-16 units: most emojis count 2), same as JS / textarea maxlength. */
    public static function length(string $s): int
    {
        return intdiv(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
    }

    public static function tagCount(string $s): int
    {
        return preg_match_all('/#[\p{L}\p{N}_]+/u', $s);
    }

    /** Is the caption inside Instagram's limits? Returns the reason when it is not. */
    public static function problem(string $s): ?string
    {
        if (self::length($s) > self::MAX_LEN) return 'Caption is too long: Instagram allows ' . self::MAX_LEN . ' characters';
        if (self::tagCount($s) > self::IG_MAX_TAGS) return 'Too many hashtags: Instagram allows ' . self::IG_MAX_TAGS;
        return null;
    }

    /** Clean, de-duplicated Latin-script hashtags; topped up with channel + broad tags; at most $max. */
    private static function tags(string $raw, array $meta, int $max): string
    {
        preg_match_all('/#?([\p{Latin}\p{N}_]{2,40})/u', $raw, $m);
        $extra = preg_replace('/[^\p{Latin}\p{N}]/u', '', $meta['channel'] ?? '');
        $list = array_merge($m[1], $extra !== '' ? [$extra] : [], array_map(fn($t) => substr($t, 1), self::BASE_TAGS));
        $seen = [];
        foreach ($list as $t) {
            if (ctype_digit($t) || isset($seen[mb_strtolower($t)]) || preg_match(self::OTHER_APPS, $t)) continue;
            $seen[mb_strtolower($t)] = '#' . $t;
            if (count($seen) >= $max) break;
        }
        return implode(' ', $seen);
    }
}
