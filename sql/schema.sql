-- AI Shorts Studio database (MySQL 8+)
CREATE DATABASE IF NOT EXISTS ai_shorts CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ai_shorts;

CREATE TABLE IF NOT EXISTS jobs (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type        ENUM('reels','ai_short') NOT NULL,
  title       VARCHAR(255) NOT NULL DEFAULT '',
  status      ENUM('queued','processing','done','failed') NOT NULL DEFAULT 'queued',
  progress    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  message     VARCHAR(255) NOT NULL DEFAULT '',
  input       JSON NOT NULL,
  error       TEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (status, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS clips (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id       INT UNSIGNED NOT NULL,
  title        VARCHAR(255) NOT NULL DEFAULT '',
  description  TEXT NULL,
  hashtags     VARCHAR(500) NOT NULL DEFAULT '',
  ig_caption   TEXT NULL,
  file         VARCHAR(255) NOT NULL,
  thumb        VARCHAR(255) NOT NULL DEFAULT '',
  start_sec    DECIMAL(10,2) NULL,
  end_sec      DECIMAL(10,2) NULL,
  duration     DECIMAL(10,2) NOT NULL DEFAULT 0,
  score        TINYINT UNSIGNED NULL,
  -- Instagram posting (T3)
  ig_status       ENUM('none','queued','posting','posted','failed','scheduled') NOT NULL DEFAULT 'none',
  ig_progress     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ig_step         VARCHAR(255) NOT NULL DEFAULT '',
  ig_error        VARCHAR(500) NULL,
  ig_container_id VARCHAR(64) NULL,
  ig_media_id     VARCHAR(64) NULL,
  ig_permalink    VARCHAR(255) NULL,
  ig_posted_at    DATETIME NULL,
  ig_tries        TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- automatic retries after a Meta-side failure
  ig_retry_at     DATETIME NULL,
  -- Facebook Page posting (T3b), separate from ig_*
  fb_status       ENUM('none','queued','posting','posted','failed','scheduled') NOT NULL DEFAULT 'none',
  fb_progress     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  fb_step         VARCHAR(255) NOT NULL DEFAULT '',
  fb_error        VARCHAR(500) NULL,
  fb_video_id     VARCHAR(64) NULL,
  fb_permalink    VARCHAR(255) NULL,
  fb_posted_at    DATETIME NULL,
  -- scheduled posting through GitHub Actions (T8); ig/fb_status 'scheduled' = waiting in the cloud plan
  sch_at          INT UNSIGNED NULL,
  sch_status      ENUM('none','uploading','planned','posting','failed') NOT NULL DEFAULT 'none',
  sch_progress    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  sch_step        VARCHAR(255) NOT NULL DEFAULT '',
  sch_error       VARCHAR(500) NULL,
  sch_asset       VARCHAR(80) NULL,
  -- framing + "change framing later" (T5), done by worker.php
  framing         VARCHAR(8) NOT NULL DEFAULT 'crop',   -- crop (centre) / left / right / blur
  rf_framing      VARCHAR(8) NULL,                      -- asked for; becomes `framing` when the new reel is ready
  rf_status       ENUM('none','queued','working','failed') NOT NULL DEFAULT 'none',
  rf_progress     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  rf_step         VARCHAR(255) NOT NULL DEFAULT '',
  rf_error        VARCHAR(500) NULL,
  -- hook intro + branded layout (T6): NULL = off, '' = title bar + progress bar only
  hook            VARCHAR(255) NULL,
  hook_voice      VARCHAR(80) NULL,                     -- AI voice MP3 file name inside the job folder
  avatar          VARCHAR(500) NULL,                    -- T6b: NULL = no avatar; JSON reactions [{"t":12.3,"type":"laugh"}]
  end_card        VARCHAR(200) NULL,                    -- T6c: NULL = no follow card; JSON {"lang":"hi","voice":"reel_1_end.mp3"}
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_clips_ig_status (ig_status, id),
  INDEX idx_clips_fb_status (fb_status, id),
  INDEX idx_clips_rf_status (rf_status, id),
  INDEX idx_clips_sch_status (sch_status, id),
  FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
  name        VARCHAR(64) NOT NULL PRIMARY KEY,
  value       TEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
