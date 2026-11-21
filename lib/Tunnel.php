<?php
declare(strict_types=1);

/**
 * One-time public link for ONE reel, so Instagram can fetch it by video_url (T3d).
 * A separate mini server (php -S on its own free port, router tools/tunnel_router.php) serves only a
 * random-named copy of that reel; every other path is 404. A Cloudflare quick tunnel (cloudflared, free,
 * no account) points at THAT port only, never at Apache / htdocs. close() kills both and deletes the copy.
 * Hard cap: tools/tunnel_guard.php kills everything after cfg('tunnel_max_min') even if the poster dies;
 * cleanupLeftover() (poster start) kills whatever a crashed poster left. Windows and Linux.
 */
class Tunnel
{
    public string $url = '';          // https://xxx.trycloudflare.com/<random>.mp4
    private array $procs = [];
    private array $pids = [];
    private string $dir = '';
    private int $port = 0;
    private int $until = 0;

    private function __construct() {}

    /** Start mini server + tunnel for $file. Throws when anything fails (everything is cleaned up first). */
    public static function open(string $file): self
    {
        $exe = (string)cfg('cloudflared');
        if ($exe === '' || !is_file($exe)) throw new RuntimeException('cloudflared is not installed');
        self::cleanupLeftover();
        $t = new self();
        try {
            $t->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aishorts_tun_' . bin2hex(random_bytes(8));
            $name = bin2hex(random_bytes(16)) . '.mp4';
            if (!@mkdir($t->dir, 0700) || !@copy($file, $t->dir . DIRECTORY_SEPARATOR . $name)) throw new RuntimeException('could not copy the reel');
            $t->port = self::freePort();
            $t->until = time() + 60 * max(1, (int)cfg('tunnel_max_min'));
            $t->save();
            $t->start('server', [self::php(), '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'expose_php=0',
                '-S', "127.0.0.1:{$t->port}", '-t', $t->dir, dirname(__DIR__) . '/tools/tunnel_router.php'],
                ['TUNNEL_FILE' => $name, 'TUNNEL_DIR' => $t->dir, 'TUNNEL_UNTIL' => (string)$t->until]);
            $t->start('guard', [self::php(), dirname(__DIR__) . '/tools/tunnel_guard.php', self::stateFile(), $t->dir]);
            if (!self::reachable("http://127.0.0.1:{$t->port}/$name", 10)) throw new RuntimeException('mini server did not start');
            $out = $t->dir . DIRECTORY_SEPARATOR . 'cloudflared.out';
            $t->start('cloudflared', [$exe, 'tunnel', '--no-autoupdate', '--logfile', $t->dir . DIRECTORY_SEPARATOR . 'cloudflared.log',
                '--url', "http://127.0.0.1:{$t->port}"], null, $out);
            $base = $t->waitUrl($out, 40);
            $t->url = "$base/$name";
            // Cloudflare needs a few seconds before the new name answers; Instagram may still reach it later
            self::reachable($t->url, 30);
            return $t;
        } catch (Throwable $e) {
            $t->close();
            throw new RuntimeException('Tunnel could not start: ' . $e->getMessage());
        }
    }

    /** Tunnel + mini server off, copy deleted. Safe to call twice. */
    public function close(): void
    {
        foreach (['cloudflared', 'server'] as $k) {
            if (isset($this->procs[$k])) { @proc_terminate($this->procs[$k], 9); @proc_close($this->procs[$k]); }
        }
        $this->procs = [];
        self::removeDir($this->dir);
        $st = self::read();
        if ($st && ($st['dir'] ?? '') === $this->dir) @unlink(self::stateFile());   // guard sees this and exits
        $this->url = '';
    }

    public function __destruct() { if ($this->procs) $this->close(); }

    public function isOpen(): bool { return $this->url !== '' && time() < $this->until; }

    /** Kill a tunnel / mini server left by a crashed poster (only processes whose command line names its folder). */
    public static function cleanupLeftover(): void
    {
        $st = self::read();
        foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aishorts_tun_*', GLOB_ONLYDIR) ?: [] as $d) {
            if ($st && $st['dir'] === $d) continue;
            self::removeDir($d);
        }
        if (!$st) return;
        foreach ((array)($st['pids'] ?? []) as $k => $pid) if ($k !== 'guard') self::killIf((int)$pid, basename($st['dir']));
        self::removeDir($st['dir']);
        @unlink(self::stateFile());
    }

    /** tools/tunnel_guard.php: wait for close() or the hard cap; at the cap kill everything. */
    public static function guard(string $dir): void
    {
        while (($st = self::read()) && $st['dir'] === $dir) {
            if (time() >= (int)$st['until']) { self::cleanupLeftover(); return; }
            sleep(2);
        }
    }

    /* ------------------------------ helpers ------------------------------ */

    private function start(string $key, array $cmd, ?array $env = null, ?string $errFile = null): void
    {
        $nul = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $p = proc_open($cmd, [0 => ['file', $nul, 'r'], 1 => ['file', $nul, 'w'], 2 => ['file', $errFile ?? $nul, 'w']],
            $pipes, null, $env === null ? null : $env + getenv());
        if (!is_resource($p)) throw new RuntimeException("could not start $key");
        $this->pids[$key] = (int)proc_get_status($p)['pid'];
        if ($key !== 'guard') $this->procs[$key] = $p;
        $this->save();
    }

    /** The https://xxx.trycloudflare.com address, once the tunnel has a connection to Cloudflare. */
    private function waitUrl(string $out, int $sec): string
    {
        $t0 = time(); $url = '';
        while (time() - $t0 < $sec) {
            $log = (string)@file_get_contents($out);
            if ($url === '' && preg_match('~https://[a-z0-9-]+\.trycloudflare\.com~', $log, $m)) $url = $m[0];
            if ($url !== '' && str_contains($log, 'Registered tunnel connection')) return $url;
            if (!proc_get_status($this->procs['cloudflared'])['running']) throw new RuntimeException('cloudflared stopped');
            usleep(300000);
        }
        throw new RuntimeException('no tunnel address from Cloudflare');
    }

    private static function reachable(string $url, int $sec): bool
    {
        $t0 = time();
        do {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_DNS_CACHE_TIMEOUT => 0, CURLOPT_FRESH_CONNECT => true]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 200) return true;
            sleep(2);
        } while (time() - $t0 < $sec);
        return false;
    }

    /** A free local port picked by the OS (never Apache's 80/443 or MySQL's 3306). */
    private static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0', $no, $err);
        if (!$s) throw new RuntimeException("no free port ($err)");
        $port = (int)substr((string)strrchr((string)stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        if ($port < 1024 || in_array($port, [3306, 8080, 8443], true)) throw new RuntimeException('bad port');
        return $port;
    }

    private static function killIf(int $pid, string $marker): void
    {
        if ($pid <= 0 || $marker === '') return;
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = (string)@shell_exec('powershell -NoProfile -NonInteractive -Command "(Get-CimInstance Win32_Process -Filter \'ProcessId=' . $pid . '\').CommandLine"');
            if (str_contains($cmd, $marker)) @exec("taskkill /F /PID $pid 2>NUL");
        } else {
            $cmd = (string)@file_get_contents("/proc/$pid/cmdline");
            if (str_contains($cmd, $marker)) @exec("kill -9 $pid 2>/dev/null");
        }
    }

    private static function removeDir(string $dir): void
    {
        if ($dir === '' || !str_starts_with(basename($dir), 'aishorts_tun_') || !is_dir($dir)) return;
        for ($i = 0; $i < 10; $i++) {   // Windows frees file handles a moment after a kill
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) @unlink($f);
            if (@rmdir($dir)) return;
            usleep(300000);
        }
    }

    private static function php(): string { return (string)(cfg('php_cli') ?: PHP_BINARY); }

    private static function stateFile(): string { return cfg('storage_dir') . '/tunnel.json'; }

    private static function read(): ?array
    {
        $j = json_decode((string)@file_get_contents(self::stateFile()), true);
        return is_array($j) && !empty($j['dir']) ? $j : null;
    }

    private function save(): void
    {
        file_put_contents(self::stateFile(), json_encode(['dir' => $this->dir, 'port' => $this->port,
            'until' => $this->until, 'pids' => $this->pids]), LOCK_EX);
    }
}
