CREATE TABLE IF NOT EXISTS travel_contents (
 id BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
 site_key VARCHAR(80) NOT NULL DEFAULT 'travel',
 content_type VARCHAR(30) NOT NULL DEFAULT 'page' CHECK(content_type IN ('page','destination','service','article')),
 slug VARCHAR(190) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published','archived')),
 locale VARCHAR(10) NOT NULL DEFAULT 'fa',
 title VARCHAR(255) NOT NULL,
 excerpt TEXT,
 body TEXT NOT NULL DEFAULT '',
 meta_title VARCHAR(255),
 meta_description TEXT,
 featured_image VARCHAR(500),
 sort_order INTEGER NOT NULL DEFAULT 0,
 author_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
 published_at TIMESTAMP,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(site_key,slug,locale)
);
CREATE INDEX IF NOT EXISTS idx_travel_contents_lookup ON travel_contents(site_key,status,content_type,locale);
UPDATE module_instances SET version='6.0.2',status='connecting',base_url='https://travel.vazin.online',capabilities='["content","travel","localization","seo"]'::jsonb,updated_at=CURRENT_TIMESTAMP WHERE module_key='vazincms-travel';
INSERT INTO schema_migrations(version) VALUES('3.0.0') ON CONFLICT(version) DO NOTHING;
