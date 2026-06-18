-- SubSync Database Schema

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS movies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    year INTEGER,
    poster_url TEXT,
    folder_path TEXT,
    imdb_id TEXT,
    rating REAL,
    genre TEXT,
    plot TEXT,
    scraped_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tv_shows (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    year INTEGER,
    poster_url TEXT,
    seasons INTEGER,
    episode_count INTEGER,
    plot TEXT,
    scraped_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tv_episodes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    show_id INTEGER NOT NULL,
    season INTEGER NOT NULL,
    episode INTEGER NOT NULL,
    title TEXT,
    file_path TEXT,
    folder_path TEXT,
    scraped_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (show_id) REFERENCES tv_shows(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sync_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    media_type TEXT NOT NULL,
    media_title TEXT,
    video_path TEXT NOT NULL,
    subtitle_path TEXT NOT NULL,
    backup_path TEXT,
    status TEXT NOT NULL DEFAULT 'pending',
    log TEXT,
    started_at DATETIME,
    completed_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sync_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    video_path TEXT NOT NULL,
    subtitle_path TEXT NOT NULL,
    media_title TEXT,
    status TEXT NOT NULL DEFAULT 'pending',
    log TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME,
    completed_at DATETIME
);

CREATE INDEX IF NOT EXISTS idx_movies_title ON movies(title);
CREATE INDEX IF NOT EXISTS idx_tv_shows_title ON tv_shows(title);
CREATE INDEX IF NOT EXISTS idx_tv_episodes_show ON tv_episodes(show_id, season, episode);
CREATE INDEX IF NOT EXISTS idx_sync_queue_status ON sync_queue(status);

-- Default settings
INSERT OR IGNORE INTO settings (key, value) VALUES ('source_type', 'kodi');
INSERT OR IGNORE INTO settings (key, value) VALUES ('kodi_host', '');
INSERT OR IGNORE INTO settings (key, value) VALUES ('kodi_port', '8080');
INSERT OR IGNORE INTO settings (key, value) VALUES ('kodi_user', 'kodi');
INSERT OR IGNORE INTO settings (key, value) VALUES ('kodi_pass', 'kodi');
INSERT OR IGNORE INTO settings (key, value) VALUES ('plex_host', '');
INSERT OR IGNORE INTO settings (key, value) VALUES ('plex_port', '32400');
INSERT OR IGNORE INTO settings (key, value) VALUES ('plex_token', '');
INSERT OR IGNORE INTO settings (key, value) VALUES ('kodi_movie_root', '');
INSERT OR IGNORE INTO settings (key, value) VALUES ('kodi_tv_root', '');
INSERT OR IGNORE INTO settings (key, value) VALUES ('paths_detected', '0');
INSERT OR IGNORE INTO settings (key, value) VALUES ('setup_complete', '0');
INSERT OR IGNORE INTO settings (key, value) VALUES ('last_scrape', '');
INSERT OR IGNORE INTO settings (key, value) VALUES ('scrape_interval', '12');
