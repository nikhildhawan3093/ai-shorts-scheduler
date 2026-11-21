<?php
/**
 * Hard cap for the Instagram tunnel (started by lib/Tunnel.php): waits until the tunnel is closed or
 * cfg('tunnel_max_min') is over, then kills tunnel + mini server and deletes the reel copy, even when
 * the poster itself has died.   php tools/tunnel_guard.php <state file> <tunnel folder>
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit("CLI only\n");
require __DIR__ . '/../lib/bootstrap.php';
set_time_limit(0);
Tunnel::guard((string)($argv[2] ?? ''));
