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

-- Create page_views
CREATE TABLE IF NOT EXISTS page_views (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page TEXT NOT NULL,
    view_date TEXT NOT NULL,
    count INTEGER NOT NULL DEFAULT 0,
    UNIQUE(page, view_date)
);

-- Create unique_page_views to track unique visitors per page/date
CREATE TABLE IF NOT EXISTS unique_page_views (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page TEXT NOT NULL,
    view_date TEXT NOT NULL,
    uniq_key TEXT NOT NULL,
    user_id TEXT,
    session_id TEXT,
    UNIQUE(page, view_date, uniq_key)
);
-- Create sessions
CREATE TABLE IF NOT EXISTS sessions (
    id TEXT PRIMARY KEY,
    payload TEXT NOT NULL,
    last_access INTEGER NOT NULL
);

-- Indexes to speed up stats queries
CREATE INDEX IF NOT EXISTS idx_unique_page_views_page_date ON unique_page_views(page, view_date);
CREATE INDEX IF NOT EXISTS idx_user_logs_user_created ON user_logs(user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_sessions_last_access ON sessions(last_access);
