-- CANDIDATE_REVIEW_ONLY: intended migration 10.31.5.
-- Source SHA256: 1ea69fb15dbd6e4698927477593d7e2709b618a3966d6ca6fb930065ccd39c30
-- Do NOT apply to live database until signed release and DR accepted.
-- VazinCMS Phase 8, PROPOSAL ONLY: durable pre-execution claims.
-- Depends on approved phase2..7 migrations and verified site identity.
-- Not installed in database/migrations; do not run on live DB.
-- Existing cms_site_local_jobs and cms_site_local_job_runs are preserved.
CREATE TABLE IF NOT EXISTS cms_site_job_claims (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 site_id INTEGER NOT NULL REFERENCES cms_sites(id) ON DELETE RESTRICT,
 job_id INTEGER NOT NULL,
 actor_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 claim_token TEXT NOT NULL UNIQUE,
 state TEXT NOT NULL CHECK(state IN('claimed','succeeded','review_required','closed')),
 result_json TEXT NOT NULL DEFAULT '{}',
 review_note TEXT NOT NULL DEFAULT '',
 reviewed_by INTEGER REFERENCES users(id) ON DELETE RESTRICT,
 claimed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 finished_at TEXT,
 reviewed_at TEXT,
 FOREIGN KEY(site_id,job_id) REFERENCES cms_site_local_jobs(site_id,id) ON DELETE RESTRICT
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_cms_site_job_active_claim
 ON cms_site_job_claims(site_id,job_id) WHERE state IN ('claimed','review_required');
CREATE INDEX IF NOT EXISTS idx_cms_site_job_claims_site
 ON cms_site_job_claims(site_id,state,claimed_at);
