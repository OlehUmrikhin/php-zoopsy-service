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

-- Per-user page view counter
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
