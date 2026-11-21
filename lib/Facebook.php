<?php
declare(strict_types=1);

/**
 * Facebook Page Reels API (T3b). The Page is the one linked to the connected Instagram account;
 * its Page token comes from GET /me/accounts with the saved user token (a Page token made from a
 * long-lived user token does not expire). Stored only in the `settings` table, never sent to JS/logs.
 * HTTP + rupload are shared with Instagram (Instagram::call / Instagram::rupload).
 */
class Facebook
{
    private static function s(string $k): string { return (string)(Brand::get($k) ?? ''); }

    /** For the dashboard: Page name or why there is none. Never the token. */
    public static function info(): array
    {
        return ['fb_page' => self::s('fb_page_name'), 'fb_error' => self::s('fb_page_error')];
    }

    /** Find the Page now if it is not saved yet (dashboard load, token saved). Never throws. */
    public static function ensure(): void
    {
        if (!Instagram::connected() || self::s('fb_page_token') !== '') return;
        try { self::page(); Brand::set('fb_page_error', ''); }
        catch (Throwable $e) { Brand::set('fb_page_error', mb_substr($e->getMessage(), 0, 300)); }
    }

    /** New / removed Instagram token: the Page is looked up again next time. */
    public static function forget(): void
    {
        foreach (['fb_page_id', 'fb_page_name', 'fb_page_token', 'fb_page_error'] as $k) Brand::set($k, '');
    }

    /** [page id, page token]; looks the Page up once and saves it. */
    private static function page(): array
    {
        if (self::s('fb_page_token') !== '') return [self::s('fb_page_id'), self::s('fb_page_token')];
        if (!Instagram::connected()) throw new RuntimeException('Instagram is not connected. Open "Instagram account" and save your token.');
        $pages = Instagram::get('me/accounts', ['fields' => 'id,name,access_token,instagram_business_account'], '', 'Facebook')['data'] ?? [];
        $pick = null;
        foreach ($pages as $p) {
            if (($p['instagram_business_account']['id'] ?? '') === Instagram::userId()) { $pick = $p; break; }
        }
        $pick ??= $pages[0] ?? null;
        if (!$pick || empty($pick['access_token']) || !ctype_digit((string)($pick['id'] ?? ''))) {
            throw new RuntimeException('No Facebook Page found for this token. Make a new token with pages_show_list + pages_manage_posts and save it.');
        }
        foreach (['fb_page_id' => (string)$pick['id'], 'fb_page_name' => (string)($pick['name'] ?? ''),
                  'fb_page_token' => (string)$pick['access_token']] as $k => $v) Brand::set($k, $v);
        return [(string)$pick['id'], (string)$pick['access_token']];
    }

    private static function post(array $body): array
    {
        [$pageId, $token] = self::page();
        return Instagram::call('POST', Instagram::graph() . "/$pageId/video_reels", $body, $token, 'Facebook');
    }

    /** Step 1: reserve a video id for the upload. */
    public static function start(): string
    {
        $r = self::post(['upload_phase' => 'start']);
        if (empty($r['video_id'])) throw new RuntimeException('Facebook did not create the reel');
        return (string)$r['video_id'];
    }

    /** Step 2: file bytes to rupload (same protocol as Instagram). */
    public static function upload(string $videoId, string $file, int $offset, callable $onProgress): void
    {
        Instagram::rupload(rtrim((string)cfg('fb_rupload'), '/') . '/' . rawurlencode($videoId),
            self::page()[1], $file, $offset, $onProgress, 'Facebook');
    }

    /** Step 3: publish the uploaded video as a public Page reel. */
    public static function finish(string $videoId, string $caption): void
    {
        $r = self::post(['upload_phase' => 'finish', 'video_id' => $videoId, 'video_state' => 'PUBLISHED']
            + ($caption !== '' ? ['description' => $caption] : []));   // all parts off: video only
        if (empty($r['success'])) throw new RuntimeException('Facebook did not publish the reel');
    }

    /** status: video_status (upload_complete / processing / ready / error) + uploading/processing/publishing_phase. */
    public static function status(string $videoId): array
    {
        return (array)(Instagram::get($videoId, ['fields' => 'status'], self::page()[1], 'Facebook')['status'] ?? []);
    }

    /** Full https://www.facebook.com/... link (Facebook may send a path only). */
    public static function permalink(string $videoId): string
    {
        $url = (string)(Instagram::get($videoId, ['fields' => 'permalink_url'], self::page()[1], 'Facebook')['permalink_url'] ?? '');
        if (str_starts_with($url, '/')) $url = 'https://www.facebook.com' . $url;
        return preg_match('~^https://(www\.|m\.|web\.)?facebook\.com/~', $url) ? $url : 'https://www.facebook.com/reel/' . rawurlencode($videoId);
    }
}
