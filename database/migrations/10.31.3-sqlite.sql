-- CANDIDATE_REVIEW_ONLY: intended migration 10.31.3.
-- Source SHA256: 620238757d41633e64115df486bad02fe68db4648a1135adde7235ed6acb8b9f
-- Do NOT apply to live database until signed release and DR accepted.
-- PROPOSAL ONLY. Never placed under official database/migrations.
-- After phase2 24-table site_id migration + phase4 unique constraints
-- + phase5 site owned job ledger (uq_cms_pages_site_id).
-- Distinct new per-site ledgers preserve old global reports for an
-- explicit audited future data migration. No automated legacy data loss.
CREATE UNIQUE INDEX IF NOT EXISTS uq_cms_pages_site_id ON cms_pages(site_id,id);

CREATE TABLE IF NOT EXISTS cms_site_seo_reports (
 site_id INTEGER NOT NULL REFERENCES cms_sites(id) ON DELETE RESTRICT,
 page_id INTEGER NOT NULL,
 score INTEGER NOT NULL CHECK(score BETWEEN 0 AND 100),
 content_hash TEXT NOT NULL,
 issues_json TEXT NOT NULL DEFAULT '[]',
 analyzed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(site_id,page_id),
 FOREIGN KEY(site_id,page_id) REFERENCES cms_pages(site_id,id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_cms_site_seo_reports_score ON cms_site_seo_reports(site_id,score,analyzed_at);

CREATE TABLE IF NOT EXISTS cms_site_content_advisories (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 site_id INTEGER NOT NULL REFERENCES cms_sites(id) ON DELETE RESTRICT,
 page_id INTEGER NOT NULL,
 rule_key TEXT NOT NULL,
 severity TEXT NOT NULL CHECK(severity IN ('info','warning','critical')),
 category TEXT NOT NULL CHECK(category IN ('seo','content','medical','legal','publishing')),
 title TEXT NOT NULL,
 message TEXT NOT NULL,
 details_json TEXT NOT NULL DEFAULT '{}',
 status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','acknowledged','resolved','ignored')),
 fingerprint TEXT NOT NULL,
 first_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 resolved_at TEXT,
 UNIQUE(site_id,page_id,rule_key),
 FOREIGN KEY(site_id,page_id) REFERENCES cms_pages(site_id,id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_cms_site_advisories_open ON cms_site_content_advisories(site_id,status,severity,last_seen_at DESC);

CREATE TABLE IF NOT EXISTS cms_site_editorial_suggestions (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 site_id INTEGER NOT NULL REFERENCES cms_sites(id) ON DELETE RESTRICT,
 suggestion_key TEXT NOT NULL,
 title TEXT NOT NULL,
 rationale TEXT NOT NULL,
 suggested_at TEXT NOT NULL,
 status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','accepted','dismissed','completed')),
 details_json TEXT NOT NULL DEFAULT '{}',
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(site_id,suggestion_key)
);
CREATE INDEX IF NOT EXISTS idx_cms_site_editorial_suggestions ON cms_site_editorial_suggestions(site_id,status,suggested_at);

CREATE TABLE IF NOT EXISTS cms_site_editorial_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 site_id INTEGER NOT NULL REFERENCES cms_sites(id) ON DELETE RESTRICT,
 page_id INTEGER NOT NULL,
 from_state TEXT NOT NULL,
 to_state TEXT NOT NULL,
 actor_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 note TEXT NOT NULL DEFAULT '',
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(site_id,page_id) REFERENCES cms_pages(site_id,id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_cms_site_editorial_page ON cms_site_editorial_events(site_id,page_id,id);
