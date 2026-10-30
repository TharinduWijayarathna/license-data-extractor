-- Licence extraction queue: one job per document in the incoming folder.
-- Timestamps are unix seconds (portable across drivers).
CREATE TABLE IF NOT EXISTS licence_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_name TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued',
    attempts INTEGER NOT NULL DEFAULT 0,
    available_at INTEGER NOT NULL,
    reserved_at INTEGER,
    records_added INTEGER NOT NULL DEFAULT 0,
    duplicates INTEGER NOT NULL DEFAULT 0,
    error TEXT,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_licence_jobs_status ON licence_jobs (status, available_at);

CREATE INDEX IF NOT EXISTS idx_licence_jobs_file ON licence_jobs (file_name);
