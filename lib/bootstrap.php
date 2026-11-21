<?php
declare(strict_types=1);

$GLOBALS['CFG'] = require __DIR__ . '/../config.php';

spl_autoload_register(function (string $class) {
    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) require $file;
});

function cfg(string $key) { return $GLOBALS['CFG'][$key] ?? null; }

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg('db');
        $pdo = new PDO(
            "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4",
            $c['user'], $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function job_update(int $id, array $fields): void
{
    $sets = implode(', ', array_map(fn($k) => "`$k` = :$k", array_keys($fields)));
    $st = db()->prepare("UPDATE jobs SET $sets WHERE id = :id");
    $st->execute($fields + ['id' => $id]);
}

/** Start a background script: worker.php (video jobs), poster.php (Instagram posts) or scheduler.php (T8 GitHub sync).
 *  No-op if it is already running: each script holds its own lock. */
function start_worker(string $script = 'worker.php'): void
{
    $worker = realpath(__DIR__ . '/../' . (in_array($script, ['worker.php', 'poster.php', 'scheduler.php'], true) ? $script : 'worker.php'));
    if (PHP_OS_FAMILY === 'Windows') {
        // PHP_BINDIR is wrong under XAMPP's Apache (C:\php); php.exe sits next to the loaded php.ini
        $php = cfg('php_cli') ?: dirname((string) php_ini_loaded_file()) . '\\php.exe';
        pclose(popen('start "" /B "' . $php . '" "' . $worker . '" --until-empty > NUL 2>&1', 'r'));
    } else {
        $php = cfg('php_cli') ?: PHP_BINDIR . '/php';
        exec(escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' --until-empty > /dev/null 2>&1 &');
    }
}

/** Something for worker.php: a video job or a "Change framing" reel. */
function work_waiting(): bool
{
    return (bool) db()->query("SELECT 1 FROM jobs WHERE status='queued'
                               UNION ALL SELECT 1 FROM clips WHERE rf_status='queued' LIMIT 1")->fetchColumn();
}

/** Thrown when the user deletes a job while it is running. */
class JobCancelled extends RuntimeException {}

function progress(int $jobId, int $pct, string $msg): void
{
    job_update($jobId, ['progress' => max(0, min(100, $pct)), 'message' => mb_substr($msg, 0, 250)]);
    $st = db()->prepare('SELECT 1 FROM jobs WHERE id = ?');
    $st->execute([$jobId]);
    if (!$st->fetchColumn()) throw new JobCancelled("Job $jobId was deleted");
    if (PHP_SAPI === 'cli') echo "[job $jobId] $pct% $msg\n";
    // steps.log in the job folder: seconds since the job's first step, once per new step (speed check)
    static $t0 = [], $last = [];
    $step = preg_replace('/[\d.,%]+/', '', $msg);
    if (($last[$jobId] ?? null) === $step) return;
    $last[$jobId] = $step;
    $t0[$jobId] ??= microtime(true);
    $dir = cfg('storage_dir') . "/job_$jobId";
    if (is_dir($dir)) @file_put_contents("$dir/steps.log", sprintf("%6.1f s  %3d%%  %s\n", microtime(true) - $t0[$jobId], $pct, $msg), FILE_APPEND);
}
