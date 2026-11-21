<?php
/**
 * T8, first step of every GitHub Actions run (cheap, no database): is a reel due within ~5 min?
 * Writes due=1/0 to $GITHUB_OUTPUT. Nothing left in the plan → the 15-min timer is switched off
 * (the laptop switches it on again when it plans new reels).   php cloud/due.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit("CLI only\n");
require __DIR__ . '/CloudState.php';

$plan = CloudState::plan();
$wait = CloudState::wait($plan, CloudState::state(), time());
$due = $wait !== null;   // anything planned → run.php waits for it inside the run (GitHub's timer is unreliable)
echo $wait === null ? "Nothing planned.\n" : "Next reel in {$wait} s.\n";
if ($out = getenv('GITHUB_OUTPUT')) file_put_contents($out, 'due=' . ($due ? 1 : 0) . "\n", FILE_APPEND);
if ($wait === null) {
    CloudState::squash();   // T8b: plan empty → clean the public history before the timer stops
    exec('gh workflow disable post.yml 2>&1', $o, $code);
    echo $code === 0 ? "Timer off until new reels are planned.\n" : 'Could not switch the timer off: ' . implode(' ', $o) . "\n";
}
