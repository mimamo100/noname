-- Unsaid database schema (MySQL 5.7+ / MariaDB 10.3+).
-- The API creates these tables automatically on first use, so importing
-- this file in phpMyAdmin is optional.

CREATE TABLE IF NOT EXISTS worlds (
  id VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  prompt_duration_ms BIGINT NOT NULL,
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
  type VARCHAR(10) CHARACTER SET ascii NOT NULL,
  letters VARCHAR(5) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
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
