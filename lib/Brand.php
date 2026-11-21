<?php
declare(strict_types=1);

/**
 * "Your page" settings: Instagram page name + logo (DB table `settings`, files in storage/brand/).
 * The logo is cut round once on upload; logo + "@page" are drawn into one badge PNG that
 * Video::makeVerticalClip overlays top-right on every new reel (same encode, no fonts needed at render time).
 */
class Brand
{
    public const LOGO_W = 130;                 // 12% of the 1080 px reel width (user: 15% → "thoda chota")
    public const TOP = 80, RIGHT = 40;         // px from the top / right edge (just below Instagram's top icons)
    public const LAYOUTS = ['left', 'right', 'below'];   // where "@page" sits next to the logo (user picks)
    private const MAX_BYTES = 2 * 1048576;
    private const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
    private const SRC = 512;                   // stored round logo size (sharp preview + badge source)

    public static function get(string $name): ?string
    {
        $st = db()->prepare('SELECT value FROM settings WHERE name=?');
        $st->execute([$name]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    /** Also used by Instagram for its settings (same `settings` table). */
    public static function set(string $name, string $value): void
    {
        db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value=VALUES(value)')
            ->execute([$name, $value]);
    }

    /** Page name without "@" (dashboard setting, else config.php default). */
    public static function page(): string
    {
        return ltrim(trim(self::get('ig_page') ?? (string)cfg('ig_page')), '@');
    }

    /** Round logo PNG (512 px), or null when none is set (T6c follow card). */
    public static function logo(): ?string
    {
        $f = self::get('logo_file');
        return $f && is_file(self::dir() . "/$f") ? self::dir() . "/$f" : null;
    }

    /** Badge PNG to burn into reels, or null when neither logo nor page name is set. */
    public static function badge(): ?string
    {
        $f = self::get('badge_file');
        return $f && is_file(self::dir() . "/$f") ? self::dir() . "/$f" : null;
    }

    /** For the dashboard. */
    public static function info(): array
    {
        $url = fn(?string $f) => $f && is_file(self::dir() . "/$f") ? cfg('storage_url') . "/brand/$f" : '';
        return ['page' => self::page(), 'layout' => self::layout(),
                'logo' => $url(self::get('logo_file')), 'badge' => $url(self::get('badge_file'))];
    }

    public static function layout(): string
    {
        $l = self::get('badge_layout');
        return in_array($l, self::LAYOUTS, true) ? $l : 'left';
    }

    public static function saveLayout(string $layout): void
    {
        self::set('badge_layout', in_array($layout, self::LAYOUTS, true) ? $layout : 'left');
    }

    /** Instagram username rules: letters, numbers, "." and "_", max 30. Returns the error or null. */
    public static function savePage(string $page): ?string
    {
        $page = ltrim(trim($page), '@');
        if (!preg_match('/^[A-Za-z0-9._]{0,30}$/', $page)) return 'Page name: only letters, numbers, . and _ (max 30)';
        self::set('ig_page', $page);
        return null;
    }

    /** Validate an uploaded logo and store it cut round. Returns the error or null. */
    public static function saveLogo(array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return 'Logo upload failed';
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(self::TYPES[$ext]) || self::TYPES[$ext] !== $mime) return 'Logo must be PNG, JPG or WebP';
        if ($file['size'] > self::MAX_BYTES) return 'Logo is too big (max 2 MB)';
        $dim = @getimagesize($file['tmp_name']);
        if (!$dim || $dim[0] < 64 || $dim[1] < 64 || $dim[0] > 6000 || $dim[1] > 6000) return 'Logo must be 64–6000 px';
        $img = @imagecreatefromstring((string)file_get_contents($file['tmp_name']));
        if (!$img) return 'Logo image could not be read';

        $name = 'logo_' . bin2hex(random_bytes(6)) . '.png';
        imagepng(self::round($img), self::dir() . "/$name");
        self::replace('logo_file', $name);
        return null;
    }

    public static function removeLogo(): void { self::replace('logo_file', ''); }

    /** Redraw the badge (round logo + "@page" left / right / below, per layout) after any change. */
    public static function rebuildBadge(): void
    {
        $logoF = self::get('logo_file');
        $logo = $logoF && is_file(self::dir() . "/$logoF") ? imagecreatefrompng(self::dir() . "/$logoF") : null;
        $page = self::page();
        $font = (string)cfg('brand_font');
        $text = $page !== '' && is_file($font) ? "@$page" : '';
        if (!$logo && $text === '') { self::replace('badge_file', ''); return; }

        $size = 30; $pad = 4; $gap = 12;
        $L = $logo ? self::LOGO_W : 0;
        $b = $text !== '' ? imagettfbbox($size, 0, $font, $text) : [0, 0, 0, 0, 0, 0, 0, 0];
        $tw = $text !== '' ? $b[2] - $b[0] + 2 * $pad : 0;           // text box incl. outline room
        $th = $text !== '' ? $b[1] - $b[7] + 2 * $pad : 0;
        $g = $L && $tw ? $gap : 0;
        $below = self::layout() === 'below';
        [$w, $h] = $below ? [max($L, $tw), $L + $g + $th] : [$L + $g + $tw, max($L, $th)];
        // top-left corners of the logo and the text box
        if ($below)                         [$lx, $ly, $tx, $ty] = [intdiv($w - $L, 2), 0, intdiv($w - $tw, 2), $L + $g];
        elseif (self::layout() === 'left')  [$lx, $ly, $tx, $ty] = [$w - $L, intdiv($h - $L, 2), 0, intdiv($h - $th, 2)];
        else                                [$lx, $ly, $tx, $ty] = [0, intdiv($h - $L, 2), $L + $g, intdiv($h - $th, 2)];

        $im = self::canvas($w, $h);
        if ($logo) imagecopyresampled($im, $logo, $lx, $ly, 0, 0, $L, $L, self::SRC, self::SRC);
        if ($text !== '') self::drawText($im, $tx + $pad - $b[0], $ty + $pad - $b[7], $size, $font, $text);
        $name = 'badge_' . bin2hex(random_bytes(6)) . '.png';
        imagepng($im, self::dir() . "/$name");
        self::replace('badge_file', $name);
    }

    /** White text with a soft dark outline (readable on any video). $x/$y = baseline start. */
    private static function drawText(GdImage $im, int $x, int $y, int $size, string $font, string $text): void
    {
        imagealphablending($im, true);
        $shadow = imagecolorallocatealpha($im, 0, 0, 0, 60);
        foreach ([[-2, 0], [2, 0], [0, -2], [0, 2], [-2, -2], [2, 2], [-2, 2], [2, -2]] as [$dx, $dy]) {
            imagettftext($im, $size, 0, $x + $dx, $y + $dy, $shadow, $font, $text);
        }
        imagettftext($im, $size, 0, $x, $y, imagecolorallocate($im, 255, 255, 255), $font, $text);
        imagealphablending($im, false);
    }

    /** Centre square crop → SRC×SRC circle with a smooth edge and a thin white ring. */
    private static function round(GdImage $src): GdImage
    {
        $n = self::SRC;
        $side = min(imagesx($src), imagesy($src));
        $im = self::canvas($n, $n);
        imagecopyresampled($im, $src, 0, 0, intdiv(imagesx($src) - $side, 2), intdiv(imagesy($src) - $side, 2), $n, $n, $side, $side);
        $r = $n / 2; $ring = $n * 0.025;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $d = hypot($x + 0.5 - $r, $y + 0.5 - $r);
                $cover = max(0.0, min(1.0, $r - $d));                     // 1 inside, 0 outside, soft 1-px edge
                if ($cover <= 0) { imagesetpixel($im, $x, $y, 0x7F000000); continue; }
                if ($d > $r - $ring) { $rgb = 0xFFFFFF; $a = 0; }          // white ring
                else { $c = imagecolorat($im, $x, $y); $rgb = $c & 0xFFFFFF; $a = ($c >> 24) & 0x7F; }
                $alpha = (int)round(127 - (127 - $a) * $cover);
                imagesetpixel($im, $x, $y, ($alpha << 24) | $rgb);
            }
        }
        return $im;
    }

    private static function canvas(int $w, int $h): GdImage
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        return $im;
    }

    /** Point a setting at a new file and delete the old one. */
    private static function replace(string $key, string $file): void
    {
        $old = self::get($key);
        self::set($key, $file);
        if ($old && $old !== $file && preg_match('/^[a-z]+_[0-9a-f]+\.png$/', $old)) @unlink(self::dir() . "/$old");
    }

    private static function dir(): string
    {
        $d = cfg('storage_dir') . '/brand';
        if (!is_dir($d)) mkdir($d, 0775, true);
        return $d;
    }
}
