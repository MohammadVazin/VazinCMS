-- CANDIDATE_REVIEW_ONLY: intended migration 10.31.4.
-- Source SHA256: 6131bc97f5a9c0b9be67490769d9685c58ad660f38aea02464db066c90f4e15c
-- Do NOT apply to live database until signed release and DR accepted.
-- VazinCMS Phase 7 — proposal only, not an official migration.
-- PRECONDITION: complete phase2 site_id, phase4 composite uniques,
-- phase5 WXR and phase6 SEO/editorial proposals; verified explicit site ownership.
-- This file is test-only. Existing global cms_extensions, scheduled_tasks and
-- api_tokens are preserved in their current form; no backfill is assumed.
CREATE UNIQUE INDEX IF NOT EXISTS uq_cms_extensions_key ON cms_extensions(extension_key);

CREATE TABLE IF NOT EXISTS cms_site_extension_preferences (
 site_id INTEGER NOT NULL REFERENCES cms_sites(id) ON DELETE RESTRICT,
 extension_key TEXT NOT NULL REFERENCES cms_extensions(extension_key) ON DELETE RESTRICT,
 desired_enabled INTEGER NOT NULL CHECK(desired_enabled IN (0,1)),
 changed_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(site_id,extension_key)
);
CREATE INDEX IF NOT EXISTS idx_cms_site_extension_preferences_actor
 ON cms_site_extension_preferences(site_id,changed_by);

CREATE TABLE IF NOT EXISTS cms_site_theme_preferences (
 site_id INTEGER PRIMARY KEY REFERENCES cms_sites(id) ON DELETE RESTRICT,
 extension_key TEXT NOT NULL REFERENCES cms_extensions(extension_key) ON DELETE RESTRICT,
 requested_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cms_site_local_jobs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 site_id INTEGER NOT NULL REFERENCES cms_sites(id) ON DELETE RESTRICT,
 job_key TEXT NOT NULL,
 task_type TEXT NOT NULL CHECK(task_type='publish_due_local'),
 is_active INTEGER NOT NULL DEFAULT 0 CHECK(is_active IN (0,1)),
 interval_minutes INTEGER NOT NULL CHECK(interval_minutes BETWEEN 5 AND 525600),
 run_as_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 next_run_at TEXT NOT NULL,
 last_run_at TEXT,
 last_status TEXT,
 locked_at TEXT,
 lock_token TEXT,
 created_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(site_id,job_key),
 UNIQUE(site_id,id)
);
CREATE INDEX IF NOT EXISTS idx_cms_site_local_jobs_due
 ON cms_site_local_jobs(site_id,is_active,next_run_at);

CREATE TABLE IF NOT EXISTS cms_site_local_job_runs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 site_id INTEGER NOT NULL REFERENCES cms_sites(id) ON DELETE RESTRICT,
 job_id INTEGER NOT NULL,
 actor_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 run_token TEXT NOT NULL,
 status TEXT NOT NULL CHECK(status IN('succeeded','failed')),
 details_json TEXT NOT NULL DEFAULT '{}',
 started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 finished_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(run_token),
 FOREIGN KEY(site_id,job_id) REFERENCES cms_site_local_jobs(site_id,id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_cms_site_local_job_runs
 ON cms_site_local_job_runs(site_id,job_id,id);

CREATE TABLE IF NOT EXISTS cms_site_api_token_grants (
 site_id INTEGER NOT NULL REFERENCES cms_sites(id) ON DELETE RESTRICT,
 token_id INTEGER NOT NULL UNIQUE REFERENCES api_tokens(id) ON DELETE RESTRICT,
 granted_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 granted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 revoked_at TEXT,
 PRIMARY KEY(site_id,token_id)
);
CREATE INDEX IF NOT EXISTS idx_cms_site_token_grants_site
 ON cms_site_api_token_grants(site_id,revoked_at);
