-- CANDIDATE_REVIEW_ONLY: intended migration 10.31.0.
-- Source SHA256: 4da2f0700f101333cb6c96d573cc3ca014c509a80a038709f91ce21c7850d0cd
-- Do NOT apply to live database until signed release and DR accepted.
-- PROPOSAL ONLY: no deployment, signed release, or migrations directory entry.
-- Must execute inside an explicit caller-owned transaction.
-- Preconditions: full 10.30.3 baseline, exactly ONE active default site.
CREATE TEMP TABLE _cms_tenant_migration_guard (n INTEGER NOT NULL CHECK(n=1));
INSERT INTO _cms_tenant_migration_guard(n) SELECT COUNT(*) FROM cms_sites;
INSERT INTO _cms_tenant_migration_guard(n) SELECT COUNT(*) FROM cms_sites WHERE site_key='default' AND status='active';
DROP TABLE _cms_tenant_migration_guard;
ALTER TABLE cms_pages ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_pages SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_pages_tenant ON cms_pages(site_id);
ALTER TABLE cms_media ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_media SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_media_tenant ON cms_media(site_id);
ALTER TABLE cms_menu_items ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_menu_items SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_menu_items_tenant ON cms_menu_items(site_id);
ALTER TABLE cms_settings ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_settings SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_settings_tenant ON cms_settings(site_id);
ALTER TABLE cms_redirects ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_redirects SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_redirects_tenant ON cms_redirects(site_id);
ALTER TABLE cms_taxonomies ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_taxonomies SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_taxonomies_tenant ON cms_taxonomies(site_id);
ALTER TABLE cms_terms ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_terms SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_terms_tenant ON cms_terms(site_id);
ALTER TABLE cms_term_relationships ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_term_relationships SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_term_relationships_tenant ON cms_term_relationships(site_id);
ALTER TABLE cms_page_revisions ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_page_revisions SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_page_revisions_tenant ON cms_page_revisions(site_id);
ALTER TABLE cms_autosaves ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_autosaves SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_autosaves_tenant ON cms_autosaves(site_id);
ALTER TABLE cms_search_index ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_search_index SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_search_index_tenant ON cms_search_index(site_id);
ALTER TABLE cms_media_collections ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_media_collections SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_media_collections_tenant ON cms_media_collections(site_id);
ALTER TABLE cms_media_derivatives ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_media_derivatives SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_media_derivatives_tenant ON cms_media_derivatives(site_id);
ALTER TABLE cms_media_collection_items ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_media_collection_items SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_media_collection_items_tenant ON cms_media_collection_items(site_id);
ALTER TABLE cms_content_types ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_content_types SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_content_types_tenant ON cms_content_types(site_id);
ALTER TABLE cms_forms ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_forms SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_forms_tenant ON cms_forms(site_id);
ALTER TABLE cms_form_fields ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_form_fields SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_form_fields_tenant ON cms_form_fields(site_id);
ALTER TABLE cms_form_submissions ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_form_submissions SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_form_submissions_tenant ON cms_form_submissions(site_id);
ALTER TABLE cms_reusable_blocks ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_reusable_blocks SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_reusable_blocks_tenant ON cms_reusable_blocks(site_id);
ALTER TABLE cms_block_patterns ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_block_patterns SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_block_patterns_tenant ON cms_block_patterns(site_id);
ALTER TABLE cms_editorial_events ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_editorial_events SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_editorial_events_tenant ON cms_editorial_events(site_id);
ALTER TABLE publication_jobs ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE publication_jobs SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_publication_jobs_tenant ON publication_jobs(site_id);
ALTER TABLE cms_custom_field_definitions ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_custom_field_definitions SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_custom_field_definitions_tenant ON cms_custom_field_definitions(site_id);
ALTER TABLE cms_custom_field_values ADD COLUMN site_id INTEGER REFERENCES cms_sites(id);
UPDATE cms_custom_field_values SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
CREATE INDEX idx_cms_custom_field_values_tenant ON cms_custom_field_values(site_id);
CREATE TRIGGER guard_cms_pages_tenant_insert BEFORE INSERT ON cms_pages BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_pages_tenant_update BEFORE UPDATE ON cms_pages BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_media_tenant_insert BEFORE INSERT ON cms_media BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_media_tenant_update BEFORE UPDATE ON cms_media BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_menu_items_tenant_insert BEFORE INSERT ON cms_menu_items BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_menu_items_tenant_update BEFORE UPDATE ON cms_menu_items BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_settings_tenant_insert BEFORE INSERT ON cms_settings BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_settings_tenant_update BEFORE UPDATE ON cms_settings BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_redirects_tenant_insert BEFORE INSERT ON cms_redirects BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_redirects_tenant_update BEFORE UPDATE ON cms_redirects BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_taxonomies_tenant_insert BEFORE INSERT ON cms_taxonomies BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_taxonomies_tenant_update BEFORE UPDATE ON cms_taxonomies BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_terms_tenant_insert BEFORE INSERT ON cms_terms BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging'))) OR ((NEW.taxonomy_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_taxonomies p WHERE p.id=NEW.taxonomy_id AND p.site_id=NEW.site_id))) OR ((NEW.parent_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_terms p WHERE p.id=NEW.parent_id AND p.site_id=NEW.site_id)));
END;
CREATE TRIGGER guard_cms_terms_tenant_update BEFORE UPDATE ON cms_terms BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging'))) OR ((NEW.taxonomy_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_taxonomies p WHERE p.id=NEW.taxonomy_id AND p.site_id=NEW.site_id))) OR ((NEW.parent_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_terms p WHERE p.id=NEW.parent_id AND p.site_id=NEW.site_id)));
END;
CREATE TRIGGER guard_cms_term_relationships_tenant_insert BEFORE INSERT ON cms_term_relationships BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging'))) OR ((NEW.page_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_pages p WHERE p.id=NEW.page_id AND p.site_id=NEW.site_id))) OR ((NEW.term_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_terms p WHERE p.id=NEW.term_id AND p.site_id=NEW.site_id)));
END;
CREATE TRIGGER guard_cms_term_relationships_tenant_update BEFORE UPDATE ON cms_term_relationships BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging'))) OR ((NEW.page_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_pages p WHERE p.id=NEW.page_id AND p.site_id=NEW.site_id))) OR ((NEW.term_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_terms p WHERE p.id=NEW.term_id AND p.site_id=NEW.site_id)));
END;
CREATE TRIGGER guard_cms_page_revisions_tenant_insert BEFORE INSERT ON cms_page_revisions BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging'))) OR ((NEW.page_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_pages p WHERE p.id=NEW.page_id AND p.site_id=NEW.site_id)));
END;
CREATE TRIGGER guard_cms_page_revisions_tenant_update BEFORE UPDATE ON cms_page_revisions BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging'))) OR ((NEW.page_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_pages p WHERE p.id=NEW.page_id AND p.site_id=NEW.site_id)));
END;
CREATE TRIGGER guard_cms_autosaves_tenant_insert BEFORE INSERT ON cms_autosaves BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging'))) OR ((NEW.page_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_pages p WHERE p.id=NEW.page_id AND p.site_id=NEW.site_id)));
END;
CREATE TRIGGER guard_cms_autosaves_tenant_update BEFORE UPDATE ON cms_autosaves BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging'))) OR ((NEW.page_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_pages p WHERE p.id=NEW.page_id AND p.site_id=NEW.site_id)));
END;
CREATE TRIGGER guard_cms_search_index_tenant_insert BEFORE INSERT ON cms_search_index BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging'))) OR ((NEW.page_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_pages p WHERE p.id=NEW.page_id AND p.site_id=NEW.site_id)));
END;
CREATE TRIGGER guard_cms_search_index_tenant_update BEFORE UPDATE ON cms_search_index BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging'))) OR ((NEW.page_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_pages p WHERE p.id=NEW.page_id AND p.site_id=NEW.site_id)));
END;
CREATE TRIGGER guard_cms_media_collections_tenant_insert BEFORE INSERT ON cms_media_collections BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_media_collections_tenant_update BEFORE UPDATE ON cms_media_collections BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_media_derivatives_tenant_insert BEFORE INSERT ON cms_media_derivatives BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging'))) OR ((NEW.media_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_media p WHERE p.id=NEW.media_id AND p.site_id=NEW.site_id)));
END;
CREATE TRIGGER guard_cms_media_derivatives_tenant_update BEFORE UPDATE ON cms_media_derivatives BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging'))) OR ((NEW.media_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_media p WHERE p.id=NEW.media_id AND p.site_id=NEW.site_id)));
END;
CREATE TRIGGER guard_cms_media_collection_items_tenant_insert BEFORE INSERT ON cms_media_collection_items BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging'))) OR ((NEW.media_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_media p WHERE p.id=NEW.media_id AND p.site_id=NEW.site_id))) OR ((NEW.collection_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_media_collections p WHERE p.id=NEW.collection_id AND p.site_id=NEW.site_id)));
END;
CREATE TRIGGER guard_cms_media_collection_items_tenant_update BEFORE UPDATE ON cms_media_collection_items BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging'))) OR ((NEW.media_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_media p WHERE p.id=NEW.media_id AND p.site_id=NEW.site_id))) OR ((NEW.collection_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_media_collections p WHERE p.id=NEW.collection_id AND p.site_id=NEW.site_id)));
END;
CREATE TRIGGER guard_cms_content_types_tenant_insert BEFORE INSERT ON cms_content_types BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_content_types_tenant_update BEFORE UPDATE ON cms_content_types BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_forms_tenant_insert BEFORE INSERT ON cms_forms BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_forms_tenant_update BEFORE UPDATE ON cms_forms BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_form_fields_tenant_insert BEFORE INSERT ON cms_form_fields BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging'))) OR ((NEW.form_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_forms p WHERE p.id=NEW.form_id AND p.site_id=NEW.site_id)));
END;
CREATE TRIGGER guard_cms_form_fields_tenant_update BEFORE UPDATE ON cms_form_fields BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging'))) OR ((NEW.form_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_forms p WHERE p.id=NEW.form_id AND p.site_id=NEW.site_id)));
END;
CREATE TRIGGER guard_cms_form_submissions_tenant_insert BEFORE INSERT ON cms_form_submissions BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging'))) OR ((NEW.form_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_forms p WHERE p.id=NEW.form_id AND p.site_id=NEW.site_id)));
END;
CREATE TRIGGER guard_cms_form_submissions_tenant_update BEFORE UPDATE ON cms_form_submissions BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging'))) OR ((NEW.form_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_forms p WHERE p.id=NEW.form_id AND p.site_id=NEW.site_id)));
END;
CREATE TRIGGER guard_cms_reusable_blocks_tenant_insert BEFORE INSERT ON cms_reusable_blocks BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_reusable_blocks_tenant_update BEFORE UPDATE ON cms_reusable_blocks BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_block_patterns_tenant_insert BEFORE INSERT ON cms_block_patterns BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_block_patterns_tenant_update BEFORE UPDATE ON cms_block_patterns BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_editorial_events_tenant_insert BEFORE INSERT ON cms_editorial_events BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging'))) OR ((NEW.page_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_pages p WHERE p.id=NEW.page_id AND p.site_id=NEW.site_id)));
END;
CREATE TRIGGER guard_cms_editorial_events_tenant_update BEFORE UPDATE ON cms_editorial_events BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging'))) OR ((NEW.page_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_pages p WHERE p.id=NEW.page_id AND p.site_id=NEW.site_id)));
END;
CREATE TRIGGER guard_publication_jobs_tenant_insert BEFORE INSERT ON publication_jobs BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging'))) OR ((NEW.page_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_pages p WHERE p.id=NEW.page_id AND p.site_id=NEW.site_id)));
END;
CREATE TRIGGER guard_publication_jobs_tenant_update BEFORE UPDATE ON publication_jobs BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging'))) OR ((NEW.page_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM cms_pages p WHERE p.id=NEW.page_id AND p.site_id=NEW.site_id)));
END;
CREATE TRIGGER guard_cms_custom_field_definitions_tenant_insert BEFORE INSERT ON cms_custom_field_definitions BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_custom_field_definitions_tenant_update BEFORE UPDATE ON cms_custom_field_definitions BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_custom_field_values_tenant_insert BEFORE INSERT ON cms_custom_field_values BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
CREATE TRIGGER guard_cms_custom_field_values_tenant_update BEFORE UPDATE ON cms_custom_field_values BEGIN
  SELECT RAISE(ABORT,'cms_tenant_scope_denied') WHERE (NEW.site_id <> OLD.site_id) OR (NEW.site_id IS NULL) OR (NOT EXISTS(SELECT 1 FROM cms_sites s WHERE s.id=NEW.site_id AND s.status IN('active','staging')));
END;
-- GLOBAL UNIQUE CONSTRAINTS remain; per-site duplicate slugs/keys NOT supported.
-- Additional workflows/extensions tables still need coverage; global gate stays active.
