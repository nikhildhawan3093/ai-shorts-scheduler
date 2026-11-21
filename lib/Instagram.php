<?php
declare(strict_types=1);

/**
 * Instagram Graph API (Instagram API with Facebook Login): account settings, token renewal,
 * and the Reels resumable-upload calls used by Poster. Token + app secret live only in the
 * `settings` table; they are never returned to the browser and never put in a URL or log.
 */
class Instagram
{
    public const NEEDED = ['instagram_basic', 'instagram_content_publish'];
    private const RENEW_DAYS = 15;            // renew the 60-day token when fewer days are left

    /* ------------------------------ settings ------------------------------ */

    private static function s(string $k): string { return (string)(Brand::get($k) ?? ''); }

    public static function connected(): bool { return self::s('ig_token') !== '' && self::s('ig_user_id') !== ''; }

    /** For the dashboard: never the token or the secret. */
    public static function info(): array
    {
        $exp = (int)self::s('ig_token_expires');
        $days = $exp > 0 ? (int)floor(($exp - time()) / 86400) : null;
        return [
            'connected' => self::connected(),
            'username'  => self::s('ig_username'),
            'expires'   => $exp > 0 ? date('j M Y', $exp) : '',
            'days_left' => $days,
            'auto_renew' => self::s('ig_app_id') !== '' && self::s('ig_app_secret') !== '',
            'app_id'    => self::s('ig_app_id'),
            'renew_error' => self::s('ig_renew_error'),
        ];
    }

    /**
     * Save a token: make it long-lived (when app id + secret are given), check its permissions,
     * find the Instagram account. Blank token = keep the saved one. Returns the error or null.
     */
    public static function connect(string $token, string $appId, string $secret, string $userId): ?string
    {
        $token = trim($token); $appId = trim($appId); $secret = trim($secret); $userId = trim($userId);
        if ($appId !== '' && !ctype_digit($appId)) return 'App ID has numbers only';
        if ($userId !== '' && !ctype_digit($userId)) return 'Instagram account ID has numbers only';
        if ($secret === '' && $appId === self::s('ig_app_id')) $secret = self::s('ig_app_secret');   // keep saved secret
        if ($token === '') $token = self::s('ig_token');
        if ($token === '') return 'Paste your access token';
        if (preg_match('/\s/', $token) || strlen($token) > 1000) return 'This does not look like an access token';
        try {
            $expires = 0;
            if ($appId !== '' && $secret !== '') [$token, $expires] = self::exchange($token, $appId, $secret);
            $dbg = self::get('debug_token', ['input_token' => $token], $token)['data'] ?? [];
            if (isset($dbg['is_valid']) && !$dbg['is_valid']) return 'This token is not valid (expired or logged out). Make a new one.';
            $missing = array_diff(self::NEEDED, (array)($dbg['scopes'] ?? self::NEEDED));
            if ($missing) return 'Token is missing permission: ' . implode(', ', $missing) . '. Add it and make a new token.';
            if (!$expires && !empty($dbg['expires_at'])) $expires = (int)$dbg['expires_at'];
            [$userId, $username] = self::findAccount($token, $userId);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
        foreach (['ig_token' => $token, 'ig_token_expires' => (string)$expires, 'ig_user_id' => $userId,
                  'ig_username' => $username, 'ig_app_id' => $appId, 'ig_app_secret' => $appId !== '' ? $secret : '',
                  'ig_renew_error' => '', 'ig_renew_tried' => (string)time()] as $k => $v) Brand::set($k, $v);
        return null;
    }

    public static function disconnect(): void
    {
        foreach (['ig_token', 'ig_token_expires', 'ig_user_id', 'ig_username', 'ig_renew_error'] as $k) Brand::set($k, '');
    }

    /** Renew the long-lived token before its 60-day expiry (at most one try per day). Never throws. */
    public static function renewIfDue(): void
    {
        $exp = (int)self::s('ig_token_expires');
        if (!self::connected() || !self::info()['auto_renew'] || $exp === 0) return;
        if ($exp - time() > self::RENEW_DAYS * 86400 || time() - (int)self::s('ig_renew_tried') < 86400) return;
        Brand::set('ig_renew_tried', (string)time());
        try {
            [$token, $newExp] = self::exchange(self::s('ig_token'), self::s('ig_app_id'), self::s('ig_app_secret'));
            Brand::set('ig_token', $token);
            Brand::set('ig_token_expires', (string)($newExp ?: $exp));
            Brand::set('ig_renew_error', '');
        } catch (Throwable $e) {
            Brand::set('ig_renew_error', mb_substr($e->getMessage(), 0, 300));
        }
    }

    /** Short- or long-lived user token → long-lived token (~60 days). [token, expires_at] */
    private static function exchange(string $token, string $appId, string $secret): array
    {
        $r = self::get('oauth/access_token', ['grant_type' => 'fb_exchange_token', 'client_id' => $appId,
            'client_secret' => $secret, 'fb_exchange_token' => $token], null);
        if (empty($r['access_token'])) throw new RuntimeException('Facebook did not return a long-lived token');
        return [(string)$r['access_token'], isset($r['expires_in']) ? time() + (int)$r['expires_in'] : 0];
    }

    /** The Instagram professional account linked to the user's Facebook Page. [id, username] */
    private static function findAccount(string $token, string $userId): array
    {
        if ($userId !== '') {
            $u = self::get($userId, ['fields' => 'id,username'], $token);
            return [$userId, (string)($u['username'] ?? '')];
        }
        $pages = self::get('me/accounts', ['fields' => 'name,instagram_business_account{id,username}'], $token)['data'] ?? [];
        foreach ($pages as $p) {
            if (!empty($p['instagram_business_account']['id'])) {
                return [(string)$p['instagram_business_account']['id'], (string)($p['instagram_business_account']['username'] ?? '')];
            }
        }
        throw new RuntimeException($pages
            ? 'Your Facebook Page has no Instagram account linked. Link your Instagram (Professional) to the Page, then save again.'
            : 'No Facebook Page found for this token. Give the token pages_show_list and pick your Page when you make it.');
    }

    /* ------------------------------ reels ------------------------------ */

    public static function userId(): string { return self::s('ig_user_id'); }

    /** Posts used in the last 24 h. [used, total] */
    public static function quota(): array
    {
        $d = self::get(self::userId() . '/content_publishing_limit', ['fields' => 'quota_usage,config'])['data'][0] ?? [];
        return [(int)($d['quota_usage'] ?? 0), (int)($d['config']['quota_total'] ?? 50)];
    }

    /** Step 1: Reels container. $videoUrl = Instagram fetches the video itself (tunnel), '' = empty container
     *  for a resumable upload. Returns the container id. */
    public static function createContainer(string $caption, string $videoUrl = ''): string
    {
        $src = $videoUrl !== '' ? ['video_url' => $videoUrl] : ['upload_type' => 'resumable'];
        $r = self::call('POST', self::graph() . '/' . self::userId() . '/media',
            ['media_type' => 'REELS'] + ($caption !== '' ? ['caption' => $caption] : []) + $src);   // all parts off: video only
        if (empty($r['id'])) throw new RuntimeException('Instagram did not create the reel');
        return (string)$r['id'];
    }

    /** Step 2: send the file bytes (from $offset) to rupload. $onProgress(int $sentTotal, int $size) */
    public static function upload(string $containerId, string $file, int $offset, callable $onProgress): void
    {
        self::rupload(rtrim((string)cfg('ig_rupload'), '/') . '/' . rawurlencode($containerId),
            self::s('ig_token'), $file, $offset, $onProgress, 'Instagram');
    }

    /** Resumable upload of a file to rupload.facebook.com (Instagram and Facebook Reels use the same protocol). */
    public static function rupload(string $url, string $token, string $file, int $offset, callable $onProgress, string $who): void
    {
        $size = (int)filesize($file);
        $fh = fopen($file, 'rb');
        fseek($fh, $offset);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fh,
            CURLOPT_INFILESIZE => $size - $offset, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 900, CURLOPT_USERAGENT => 'AI-Shorts-Studio/1.0',
            CURLOPT_HTTPHEADER => ['Authorization: OAuth ' . $token, "offset: $offset", "file_size: $size", 'Expect:'],
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($c, $dlT, $dl, $ulT, $ul) use ($onProgress, $offset, $size) {
                $onProgress(min($size, $offset + (int)$ul), $size);
                return 0;
            },
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);
        if ($res === false) throw new IgNetworkError("Upload stopped: $err");
        $j = json_decode((string)$res, true);
        if (!is_array($j)) throw new IgBusy("$who is having problems (unreadable answer to the upload, HTTP $code).");
        if (empty($j['success'])) {
            // a temporary problem on Meta's side ("Request processing failed", seen for hours on 2026-11-19)
            $why = (string)($j['debug_info']['message'] ?? $j['error']['message'] ?? "HTTP $code");
            throw new IgBusy("$who is having problems and did not take the video ($why).");
        }
    }

    /** Container state: status_code (IN_PROGRESS / FINISHED / ERROR / EXPIRED / PUBLISHED), status, and
     *  video_status (upload phases; only meaningful for resumable uploads, so the tunnel path skips it). */
    public static function containerStatus(string $containerId, bool $resumable = true): array
    {
        return self::get($containerId, ['fields' => 'status_code,status' . ($resumable ? ',video_status' : '')]);
    }

    /** Step 4: publish a FINISHED container. Returns the media id. */
    public static function publish(string $containerId): string
    {
        $r = self::call('POST', self::graph() . '/' . self::userId() . '/media_publish', ['creation_id' => $containerId]);
        if (empty($r['id'])) throw new RuntimeException('Instagram did not publish the reel');
        return (string)$r['id'];
    }

    public static function permalink(string $mediaId): string
    {
        $url = (string)(self::get($mediaId, ['fields' => 'permalink'])['permalink'] ?? '');
        return preg_match('~^https://(www\.)?instagram\.com/~', $url) ? $url : '';
    }

    /* ------------------------------ HTTP ------------------------------ */

    public static function graph(): string { return rtrim((string)cfg('ig_graph'), '/'); }

    /** GET on the Graph API. $token '' = saved user token, null = no token (app id + secret are in $params). */
    public static function get(string $path, array $params = [], ?string $token = '', string $who = 'Instagram'): array
    {
        return self::call('GET', self::graph() . '/' . $path . '?' . http_build_query($params), [], $token, $who);
    }

    /** Token goes in the Authorization header, never in the URL. Graph errors → short plain message. */
    public static function call(string $method, string $url, array $body = [], ?string $token = '', string $who = 'Instagram'): array
    {
        $token = $token === '' ? self::s('ig_token') : $token;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'AI-Shorts-Studio/1.0',
            CURLOPT_HTTPHEADER => $token !== null ? ['Authorization: OAuth ' . $token] : [],
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
        }
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) throw new IgNetworkError("Could not reach $who ($err). Check the internet connection.");
        $j = json_decode((string)$res, true);
        if (!is_array($j)) throw new RuntimeException("$who sent an unreadable answer (HTTP $code)");
        if (isset($j['error']) || $code >= 400) {
            $e = $j['error'] ?? [];
            $limit = $who === 'Instagram' && ((int)($e['code'] ?? 0) === 9 || (int)($e['error_subcode'] ?? 0) === 2207042);
            throw $limit ? new IgLimitReached(self::friendly($j, $who)) : new RuntimeException(self::friendly($j, $who));
        }
        return $j;
    }

    /** Graph error → one clear sentence for the dashboard. */
    private static function friendly(array $j, string $who = 'Instagram'): string
    {
        $e = $j['error'] ?? [];
        $code = (int)($e['code'] ?? 0); $sub = (int)($e['error_subcode'] ?? 0);
        $msg = trim((string)($e['error_user_msg'] ?? $e['message'] ?? 'unknown error'));
        if ($who === 'Facebook') return match (true) {
            $code === 190 => 'Facebook Page token is wrong or expired. Open "Instagram account" and save the token again.',
            in_array($code, [10, 200, 3], true) || ($code >= 200 && $code < 300)
                => 'Token has no permission to post on the Facebook Page. It needs pages_manage_posts. Make a new token and save it.',
            in_array($code, [4, 17, 32, 368, 613], true) => 'Too many requests to Facebook. Wait an hour and try again.',
            default => 'Facebook: ' . mb_substr($msg, 0, 200),
        };
        return match (true) {
            $code === 190 => 'Instagram token is wrong or expired. Open "Instagram account" and save a new token.',
            in_array($code, [10, 200, 3], true) || ($code >= 200 && $code < 300)
                => 'Token has no permission to post. It needs instagram_basic + instagram_content_publish.',
            $code === 9 || $sub === 2207042 => 'Instagram allows 50 posts in 24 hours and the limit is reached. Try again later.',
            in_array($code, [4, 17, 32, 613], true) => 'Too many requests to Instagram. Wait an hour and try again.',
            $code === 100 && str_contains($msg, 'does not exist') => 'Instagram account not found. Save the Instagram account again.',
            default => 'Instagram: ' . mb_substr($msg, 0, 200),
        };
    }
}

/** Network problem (no answer at all): safe to retry. */
class IgNetworkError extends RuntimeException {}

/** Meta's side failed to take the video (temporary): poster.php tries again by itself later. */
class IgBusy extends RuntimeException {}

/** Instagram's 50 posts / 24 h limit is reached: the poster stops every other queued Instagram post too. */
class IgLimitReached extends RuntimeException {}
