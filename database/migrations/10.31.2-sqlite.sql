-- CANDIDATE_REVIEW_ONLY: intended migration 10.31.2.
-- Source SHA256: 41366ce6f31359fced22fb904cc980cd18381e628fc7f4a9ce5f5d6e5dbf859d
-- Do NOT apply to live database until signed release and DR accepted.
-- PROPOSAL ONLY, NOT REGISTERED AS OFFICIAL MIGRATION.
-- Requires phase-2 site_id and phase-4 uniqueness proposals, reviewed owner-site map.
-- Existing global cms_import_jobs / cms_import_mappings remain intact and untouched.
-- New scoped import ledger is independent. Do not execute on production.
CREATE UNIQUE INDEX IF NOT EXISTS uq_cms_pages_site_id ON cms_pages(site_id,id);

CREATE TABLE IF NOT EXISTS cms_site_import_jobs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 site_id INTEGER NOT NULL REFERENCES cms_sites(id) ON DELETE RESTRICT,
 source_type TEXT NOT NULL DEFAULT 'wordpress_wxr',
 source_fingerprint TEXT NOT NULL,
 status TEXT NOT NULL DEFAULT 'processing' CHECK(status IN('processing','completed','failed')),
 created_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 options_json TEXT NOT NULL DEFAULT '{}',
 stats_json TEXT NOT NULL DEFAULT '{}',
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 finished_at TEXT,
 UNIQUE(site_id,source_fingerprint),
 UNIQUE(site_id,id)
);
CREATE INDEX IF NOT EXISTS idx_cms_site_import_jobs_site ON cms_site_import_jobs(site_id,status,id);

CREATE TABLE IF NOT EXISTS cms_site_import_items (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 site_id INTEGER NOT NULL REFERENCES cms_sites(id) ON DELETE RESTRICT,
 job_id INTEGER NOT NULL,
 source_post_id TEXT NOT NULL,
 target_page_id INTEGER NOT NULL,
 original_url TEXT NOT NULL DEFAULT '',
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(site_id,job_id) REFERENCES cms_site_import_jobs(site_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(site_id,target_page_id) REFERENCES cms_pages(site_id,id) ON DELETE RESTRICT,
 UNIQUE(site_id,job_id,source_post_id)
);
CREATE INDEX IF NOT EXISTS idx_cms_site_import_items_site_page ON cms_site_import_items(site_id,target_page_id);
