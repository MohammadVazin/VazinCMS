-- CANDIDATE_REVIEW_ONLY: intended migration 10.31.6.
-- Source SHA256: d672840274ace4c87e95b91c7196d2f5e5fbef49f6944b19a952c5a37abfc562
-- Do NOT apply to live database until signed release and DR accepted.
-- VazinCMS Phase 9 candidate proposal. NOT an official migration.
-- Prerequisite: reviewed phase2..8 schema, explicit owner/site membership,
-- verified domain, and a signed migration/rollback plan.
-- Token plaintext is NEVER stored; it is returned once to the issuer.
CREATE TABLE IF NOT EXISTS cms_site_worker_credentials (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 site_id INTEGER NOT NULL REFERENCES cms_sites(id) ON DELETE RESTRICT,
 job_id INTEGER NOT NULL,
 actor_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 issued_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 token_prefix TEXT NOT NULL,
 token_hash TEXT NOT NULL UNIQUE,
 task_type TEXT NOT NULL DEFAULT 'publish_due_local'
     CHECK(task_type='publish_due_local'),
 issued_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at TEXT NOT NULL,
 last_used_at TEXT,
 revoked_at TEXT,
 FOREIGN KEY(site_id,job_id) REFERENCES cms_site_local_jobs(site_id,id) ON DELETE RESTRICT
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_cms_site_worker_live_job
 ON cms_site_worker_credentials(site_id,job_id) WHERE revoked_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_cms_site_worker_expiry
 ON cms_site_worker_credentials(site_id,expires_at,revoked_at);
