-- REVIEW CANDIDATE ONLY. Intended migration version 10.31.2.
-- Source SHA256: 318f95a80ff47a4d7819b3e35e3f6d9b30dc8cf487d2d686038ab2e2b5d8b550
-- Production execution forbidden pending signed release and DR approval.
-- The official migrate.php owns BEGIN/COMMIT; no nested transaction.
-- PROPOSAL ONLY, NOT REGISTERED AS OFFICIAL MIGRATION.
-- Requires reviewed pgsql phase-2 site_id plus phase-4 unique constraints.
-- Preserves old global WordPress importer ledgers. No live deployment.
CREATE UNIQUE INDEX IF NOT EXISTS uq_cms_pages_site_id ON cms_pages(site_id,id);
CREATE TABLE IF NOT EXISTS cms_site_import_jobs (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 site_id BIGINT NOT NULL REFERENCES cms_sites(id) ON DELETE RESTRICT,
 source_type VARCHAR(32) NOT NULL DEFAULT 'wordpress_wxr',
 source_fingerprint VARCHAR(64) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'processing'
     CHECK(status IN('processing','completed','failed')),
 created_by BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 options_json TEXT NOT NULL DEFAULT '{}',
 stats_json TEXT NOT NULL DEFAULT '{}',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 finished_at TIMESTAMP,
 UNIQUE(site_id,source_fingerprint),
 UNIQUE(site_id,id)
);
CREATE INDEX IF NOT EXISTS idx_cms_site_import_jobs_site
 ON cms_site_import_jobs(site_id,status,id);
CREATE TABLE IF NOT EXISTS cms_site_import_items (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 site_id BIGINT NOT NULL REFERENCES cms_sites(id) ON DELETE RESTRICT,
 job_id BIGINT NOT NULL,
 source_post_id VARCHAR(190) NOT NULL,
 target_page_id BIGINT NOT NULL,
 original_url TEXT NOT NULL DEFAULT '',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(site_id,job_id) REFERENCES cms_site_import_jobs(site_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(site_id,target_page_id) REFERENCES cms_pages(site_id,id) ON DELETE RESTRICT,
 UNIQUE(site_id,job_id,source_post_id)
);
CREATE INDEX IF NOT EXISTS idx_cms_site_import_items_site_page
 ON cms_site_import_items(site_id,target_page_id);
