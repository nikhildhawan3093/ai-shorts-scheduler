<?php
/**
 * Router of the tunnel's mini server (php -S, started by lib/Tunnel.php). Serves ONLY the one reel copy
 * named in TUNNEL_FILE (GET/HEAD, byte ranges) until TUNNEL_UNTIL. Every other path, method or late
 * request gets a bare 404: no listing, no PHP, no other file. Never returns false (no static serving).
 */
declare(strict_types=1);

$name  = (string)getenv('TUNNEL_FILE');
$dir   = (string)getenv('TUNNEL_DIR');
$until = (int)getenv('TUNNEL_UNTIL');
$path  = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
$file  = $dir . DIRECTORY_SEPARATOR . $name;

if (!preg_match('/^[a-f0-9]{32}\.mp4$/', $name) || $path !== "/$name" || !in_array($method, ['GET', 'HEAD'], true)
    || time() > $until || !is_file($file)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Not found';
    return true;
}

$size = (int)filesize($file);
$start = 0;
$end = $size - 1;
if (preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') {
        $start = max(0, $size - (int)$m[2]);
    } else {
        $start = (int)$m[1];
        if ($m[2] !== '') $end = min($end, (int)$m[2]);
    }
    if ($start > $end) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        return true;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Type: video/mp4');
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($end - $start + 1));
header('Cache-Control: no-store');
if ($method === 'HEAD') return true;

$fh = fopen($file, 'rb');
fseek($fh, $start);
for ($left = $end - $start + 1; $left > 0 && !feof($fh) && !connection_aborted(); $left -= strlen($buf)) {
    $buf = (string)fread($fh, min(1048576, $left));
    echo $buf;
    flush();
}
fclose($fh);
return true;
