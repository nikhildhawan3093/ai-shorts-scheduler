<?php
// ---- AI Shorts Studio: config for the GitHub Actions poster (T8). Sent to the repo as config.php. ----
// No keys here: tokens + page names come from settings.enc, decrypted with the repo secret SCHED_KEY.
// The runner's own MySQL 8 (root/root) holds them only while the run lasts.
return [
    'db' => ['host' => '127.0.0.1', 'name' => 'ai_shorts', 'user' => 'root', 'pass' => 'root'],
    'storage_dir' => __DIR__ . '/work/storage',
    'storage_url' => 'storage',
    'ig_page'     => '',
    // same Meta endpoints as the laptop's config.php
    'ig_graph'   => 'https://graph.facebook.com/v27.0',
    'ig_rupload' => 'https://rupload.facebook.com/ig-api-upload/v27.0',
    'fb_rupload' => 'https://rupload.facebook.com/video-upload/v27.0',
    'ig_poll_sec' => 5,
    // Instagram fetches the reel by video_url through a quick tunnel started ON THE RUNNER (same as T3d)
    'ig_tunnel'      => true,
    'tunnel_max_min' => 15,
    'cloudflared'    => '/usr/local/bin/cloudflared',
    'app_password'   => '',
];
