-- Create user_logs
CREATE TABLE IF NOT EXISTS user_logs (
    id TEXT PRIMARY KEY,
    user_id TEXT,
    event_type TEXT NOT NULL,
    data TEXT,
    ip TEXT,
    user_agent TEXT,
    created_at TEXT NOT NULL
);

-- Create page_views (global counter)
CREATE TABLE IF NOT EXISTS page_views (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page TEXT NOT NULL,
    view_date TEXT NOT NULL,
    count INTEGER NOT NULL DEFAULT 0,
    UNIQUE(page, view_date)
);

-- Unique visitors per page/date (session-based)
CREATE TABLE IF NOT EXISTS unique_page_views (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page TEXT NOT NULL,
    view_date TEXT NOT NULL,
    uniq_key TEXT NOT NULL,
    user_id TEXT,
    session_id TEXT,
    UNIQUE(page, view_date, uniq_key)
);

-- Per-user page view counter (JWT userId-based)
CREATE TABLE IF NOT EXISTS user_page_views (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id TEXT NOT NULL,
    page TEXT NOT NULL,
    view_date TEXT NOT NULL,
    count INTEGER NOT NULL DEFAULT 0,
    UNIQUE(user_id, page, view_date)
);

-- Create sessions
CREATE TABLE IF NOT EXISTS sessions (
    id TEXT PRIMARY KEY,
    payload TEXT NOT NULL,
    last_access INTEGER NOT NULL
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_unique_page_views_page_date ON unique_page_views(page, view_date);
CREATE INDEX IF NOT EXISTS idx_user_page_views_user_page ON user_page_views(user_id, page, view_date);
CREATE INDEX IF NOT EXISTS idx_user_logs_user_created ON user_logs(user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_sessions_last_access ON sessions(last_access);
