-- Unsaid database schema (MySQL 5.7+ / MariaDB 10.3+).
-- The API creates these tables automatically on first use, so importing
-- this file in phpMyAdmin is optional.

CREATE TABLE IF NOT EXISTS worlds (
  id VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  prompt_duration_ms BIGINT NOT NULL,
  -- Which spellings count: 'uk', 'us' or 'both'.
  spelling VARCHAR(4) CHARACTER SET ascii NOT NULL DEFAULT 'both',
  created_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS players (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  world_id VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  -- Case-insensitive collation, so "Robin" and "robin" can't both join a world.
  nickname VARCHAR(40) NOT NULL,
  token CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at BIGINT NOT NULL,
  UNIQUE KEY uniq_token (token),
  UNIQUE KEY uniq_nickname (world_id, nickname),
  FOREIGN KEY (world_id) REFERENCES worlds(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prompts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  world_id VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  -- The prompt as JSON: {"cat": "bird" or null, "rules": [["starts", "b"], ...]}. See src/Prompts.php.
  spec TEXT NULL,
  label VARCHAR(200) NULL,
  -- Only used by prompts created before the spec column existed.
  type VARCHAR(10) CHARACTER SET ascii NULL,
  letters VARCHAR(5) CHARACTER SET ascii COLLATE ascii_bin NULL,
  available_at_start INT NOT NULL,
  started_at BIGINT NOT NULL,
  ends_at BIGINT NOT NULL,
  ended_at BIGINT NULL,
  KEY idx_active (world_id, ended_at),
  FOREIGN KEY (world_id) REFERENCES worlds(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS burns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  world_id VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  word VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  played_word VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  player_id INT UNSIGNED NOT NULL,
  prompt_id INT UNSIGNED NOT NULL,
  points INT NOT NULL,
  burned_at BIGINT NOT NULL,
  UNIQUE KEY uniq_word (world_id, word),
  KEY idx_played (world_id, played_word),
  FOREIGN KEY (world_id) REFERENCES worlds(id),
  FOREIGN KEY (player_id) REFERENCES players(id),
  FOREIGN KEY (prompt_id) REFERENCES prompts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wrong guesses. Most cost a small penalty, and all count towards the rate limit.
CREATE TABLE IF NOT EXISTS misses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  world_id VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  player_id INT UNSIGNED NOT NULL,
  prompt_id INT UNSIGNED NOT NULL,
  word VARCHAR(40) NOT NULL,
  reason VARCHAR(20) CHARACTER SET ascii NOT NULL,
  points INT NOT NULL,
  missed_at BIGINT NOT NULL,
  KEY idx_player (player_id, missed_at),
  FOREIGN KEY (world_id) REFERENCES worlds(id),
  FOREIGN KEY (player_id) REFERENCES players(id),
  FOREIGN KEY (prompt_id) REFERENCES prompts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Words players think should count for a meaning prompt ("squash" for "A sport or game").
-- Review them in phpMyAdmin and add good ones to data/category-overrides.txt.
CREATE TABLE IF NOT EXISTS reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  world_id VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  player_id INT UNSIGNED NOT NULL,
  prompt_id INT UNSIGNED NOT NULL,
  category VARCHAR(20) CHARACTER SET ascii NOT NULL,
  word VARCHAR(40) NOT NULL,
  reported_at BIGINT NOT NULL,
  UNIQUE KEY uniq_report (player_id, prompt_id, word),
  KEY idx_category (category, word),
  FOREIGN KEY (world_id) REFERENCES worlds(id),
  FOREIGN KEY (player_id) REFERENCES players(id),
  FOREIGN KEY (prompt_id) REFERENCES prompts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The admin's decisions about reported words. Accepted words count straight away:
-- the game adds them to the dictionary on every request. An empty category means
-- "add to the dictionary", without a meaning.
CREATE TABLE IF NOT EXISTS word_decisions (
  category VARCHAR(20) CHARACTER SET ascii NOT NULL,
  word VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  decision VARCHAR(10) CHARACTER SET ascii NOT NULL,
  decided_at BIGINT NOT NULL,
  PRIMARY KEY (category, word)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Accounts. Everyone needs one to play; signing in is by a code sent to their email.
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  -- Shown as the player's name in every world. Case-insensitive, so "Ann" and "ann" clash.
  name VARCHAR(40) NOT NULL,
  created_at BIGINT NOT NULL,
  last_seen_at BIGINT NOT NULL,
  UNIQUE KEY uniq_email (email),
  UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time sign-in codes. Only a hash of each code is stored.
CREATE TABLE IF NOT EXISTS login_codes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  code_hash CHAR(64) CHARACTER SET ascii NOT NULL,
  ip VARCHAR(45) CHARACTER SET ascii NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  used TINYINT NOT NULL DEFAULT 0,
  created_at BIGINT NOT NULL,
  expires_at BIGINT NOT NULL,
  KEY idx_email (email, created_at),
  KEY idx_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Signed-in devices. Only a hash of each session token is stored.
CREATE TABLE IF NOT EXISTS sessions (
  token_hash CHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  created_at BIGINT NOT NULL,
  expires_at BIGINT NOT NULL,
  KEY idx_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
