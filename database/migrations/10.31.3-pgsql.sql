-- REVIEW CANDIDATE ONLY. Intended migration version 10.31.3.
-- Source SHA256: 842d6c593eb1444ba757854c2e03839b8d4ecc153bf0fafbf2d9f6d931f3acfa
-- Production execution forbidden pending signed release and DR approval.
-- The official migrate.php owns BEGIN/COMMIT; no nested transaction.
-- PROPOSAL ONLY, not part of official migrations.
-- Requires full prior phase2/4/5 migration acceptance and explicit site owner mapping.
-- Retains legacy SEO/editorial tables for separately reviewed backfill.
CREATE UNIQUE INDEX IF NOT EXISTS uq_cms_pages_site_id ON cms_pages(site_id,id);

CREATE TABLE IF NOT EXISTS cms_site_seo_reports (
 site_id BIGINT NOT NULL REFERENCES cms_sites(id) ON DELETE RESTRICT,
 page_id BIGINT NOT NULL,
 score INTEGER NOT NULL CHECK(score BETWEEN 0 AND 100),
 content_hash VARCHAR(64) NOT NULL,
 issues_json TEXT NOT NULL DEFAULT '[]',
 analyzed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(site_id,page_id),
 FOREIGN KEY(site_id,page_id) REFERENCES cms_pages(site_id,id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_cms_site_seo_reports_score ON cms_site_seo_reports(site_id,score,analyzed_at);

CREATE TABLE IF NOT EXISTS cms_site_content_advisories (
 id BIGSERIAL PRIMARY KEY,
 site_id BIGINT NOT NULL REFERENCES cms_sites(id) ON DELETE RESTRICT,
 page_id BIGINT NOT NULL,
 rule_key VARCHAR(100) NOT NULL,
 severity VARCHAR(16) NOT NULL CHECK(severity IN ('info','warning','critical')),
 category VARCHAR(20) NOT NULL CHECK(category IN ('seo','content','medical','legal','publishing')),
 title VARCHAR(255) NOT NULL,
 message TEXT NOT NULL,
 details_json TEXT NOT NULL DEFAULT '{}',
 status VARCHAR(20) NOT NULL DEFAULT 'open' CHECK(status IN ('open','acknowledged','resolved','ignored')),
 fingerprint VARCHAR(64) NOT NULL,
 first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 resolved_at TIMESTAMP,
 UNIQUE(site_id,page_id,rule_key),
 FOREIGN KEY(site_id,page_id) REFERENCES cms_pages(site_id,id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_cms_site_advisories_open ON cms_site_content_advisories(site_id,status,severity,last_seen_at DESC);

CREATE TABLE IF NOT EXISTS cms_site_editorial_suggestions (
 id BIGSERIAL PRIMARY KEY,
 site_id BIGINT NOT NULL REFERENCES cms_sites(id) ON DELETE RESTRICT,
 suggestion_key VARCHAR(190) NOT NULL,
 title TEXT NOT NULL,
 rationale TEXT NOT NULL,
 suggested_at TIMESTAMP NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'open' CHECK(status IN ('open','accepted','dismissed','completed')),
 details_json TEXT NOT NULL DEFAULT '{}',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(site_id,suggestion_key)
);
CREATE INDEX IF NOT EXISTS idx_cms_site_editorial_suggestions ON cms_site_editorial_suggestions(site_id,status,suggested_at);

CREATE TABLE IF NOT EXISTS cms_site_editorial_events (
 id BIGSERIAL PRIMARY KEY,
 site_id BIGINT NOT NULL REFERENCES cms_sites(id) ON DELETE RESTRICT,
 page_id BIGINT NOT NULL,
 from_state VARCHAR(32) NOT NULL,
 to_state VARCHAR(32) NOT NULL,
 actor_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 note TEXT NOT NULL DEFAULT '',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(site_id,page_id) REFERENCES cms_pages(site_id,id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_cms_site_editorial_page ON cms_site_editorial_events(site_id,page_id,id);
