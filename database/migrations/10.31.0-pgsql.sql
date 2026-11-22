-- REVIEW CANDIDATE ONLY. Intended migration version 10.31.0.
-- Source SHA256: bb9904cbb815464d790cd9921a219a67d5b5bafd4bb7a560bb90e81677e04720
-- Production execution forbidden pending signed release and DR approval.
-- The official migrate.php owns BEGIN/COMMIT; no nested transaction.
-- PROPOSAL ONLY: requires independent PG acceptance, no production execution.
-- Must execute in the caller transaction after full 10.30.3 schema.
DO $scope$ BEGIN
IF (SELECT COUNT(*) FROM cms_sites) <> 1
   OR (SELECT COUNT(*) FROM cms_sites WHERE site_key='default' AND status='active') <> 1 THEN
 RAISE EXCEPTION 'cms_tenant_migration_requires_exactly_one_active_default_site';
END IF;
END $scope$;
ALTER TABLE cms_pages ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_pages SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_pages ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_pages_tenant ON cms_pages(site_id);
ALTER TABLE cms_media ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_media SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_media ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_media_tenant ON cms_media(site_id);
ALTER TABLE cms_menu_items ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_menu_items SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_menu_items ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_menu_items_tenant ON cms_menu_items(site_id);
ALTER TABLE cms_settings ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_settings SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_settings ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_settings_tenant ON cms_settings(site_id);
ALTER TABLE cms_redirects ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_redirects SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_redirects ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_redirects_tenant ON cms_redirects(site_id);
ALTER TABLE cms_taxonomies ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_taxonomies SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_taxonomies ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_taxonomies_tenant ON cms_taxonomies(site_id);
ALTER TABLE cms_terms ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_terms SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_terms ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_terms_tenant ON cms_terms(site_id);
ALTER TABLE cms_term_relationships ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_term_relationships SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_term_relationships ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_term_relationships_tenant ON cms_term_relationships(site_id);
ALTER TABLE cms_page_revisions ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_page_revisions SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_page_revisions ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_page_revisions_tenant ON cms_page_revisions(site_id);
ALTER TABLE cms_autosaves ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_autosaves SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_autosaves ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_autosaves_tenant ON cms_autosaves(site_id);
ALTER TABLE cms_search_index ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_search_index SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_search_index ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_search_index_tenant ON cms_search_index(site_id);
ALTER TABLE cms_media_collections ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_media_collections SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_media_collections ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_media_collections_tenant ON cms_media_collections(site_id);
ALTER TABLE cms_media_derivatives ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_media_derivatives SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_media_derivatives ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_media_derivatives_tenant ON cms_media_derivatives(site_id);
ALTER TABLE cms_media_collection_items ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_media_collection_items SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_media_collection_items ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_media_collection_items_tenant ON cms_media_collection_items(site_id);
ALTER TABLE cms_content_types ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_content_types SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_content_types ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_content_types_tenant ON cms_content_types(site_id);
ALTER TABLE cms_forms ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_forms SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_forms ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_forms_tenant ON cms_forms(site_id);
ALTER TABLE cms_form_fields ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_form_fields SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_form_fields ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_form_fields_tenant ON cms_form_fields(site_id);
ALTER TABLE cms_form_submissions ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_form_submissions SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_form_submissions ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_form_submissions_tenant ON cms_form_submissions(site_id);
ALTER TABLE cms_reusable_blocks ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_reusable_blocks SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_reusable_blocks ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_reusable_blocks_tenant ON cms_reusable_blocks(site_id);
ALTER TABLE cms_block_patterns ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_block_patterns SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_block_patterns ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_block_patterns_tenant ON cms_block_patterns(site_id);
ALTER TABLE cms_editorial_events ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_editorial_events SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_editorial_events ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_editorial_events_tenant ON cms_editorial_events(site_id);
ALTER TABLE publication_jobs ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE publication_jobs SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE publication_jobs ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_publication_jobs_tenant ON publication_jobs(site_id);
ALTER TABLE cms_custom_field_definitions ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_custom_field_definitions SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_custom_field_definitions ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_custom_field_definitions_tenant ON cms_custom_field_definitions(site_id);
ALTER TABLE cms_custom_field_values ADD COLUMN site_id BIGINT REFERENCES cms_sites(id);
UPDATE cms_custom_field_values SET site_id=(SELECT id FROM cms_sites WHERE site_key='default');
ALTER TABLE cms_custom_field_values ALTER COLUMN site_id SET NOT NULL;
CREATE INDEX idx_cms_custom_field_values_tenant ON cms_custom_field_values(site_id);
CREATE UNIQUE INDEX idx_cms_forms_site_parent ON cms_forms(site_id,id);
CREATE UNIQUE INDEX idx_cms_media_site_parent ON cms_media(site_id,id);
CREATE UNIQUE INDEX idx_cms_media_collections_site_parent ON cms_media_collections(site_id,id);
CREATE UNIQUE INDEX idx_cms_pages_site_parent ON cms_pages(site_id,id);
CREATE UNIQUE INDEX idx_cms_taxonomies_site_parent ON cms_taxonomies(site_id,id);
CREATE UNIQUE INDEX idx_cms_terms_site_parent ON cms_terms(site_id,id);
ALTER TABLE cms_terms ADD CONSTRAINT fk_cms_terms_taxonomy_id_tenant
 FOREIGN KEY (site_id,taxonomy_id) REFERENCES cms_taxonomies(site_id,id)
 ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE cms_terms ADD CONSTRAINT fk_cms_terms_parent_id_tenant
 FOREIGN KEY (site_id,parent_id) REFERENCES cms_terms(site_id,id)
 ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE cms_term_relationships ADD CONSTRAINT fk_cms_term_relationships_page_id_tenant
 FOREIGN KEY (site_id,page_id) REFERENCES cms_pages(site_id,id)
 ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE cms_term_relationships ADD CONSTRAINT fk_cms_term_relationships_term_id_tenant
 FOREIGN KEY (site_id,term_id) REFERENCES cms_terms(site_id,id)
 ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE cms_page_revisions ADD CONSTRAINT fk_cms_page_revisions_page_id_tenant
 FOREIGN KEY (site_id,page_id) REFERENCES cms_pages(site_id,id)
 ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE cms_autosaves ADD CONSTRAINT fk_cms_autosaves_page_id_tenant
 FOREIGN KEY (site_id,page_id) REFERENCES cms_pages(site_id,id)
 ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE cms_search_index ADD CONSTRAINT fk_cms_search_index_page_id_tenant
 FOREIGN KEY (site_id,page_id) REFERENCES cms_pages(site_id,id)
 ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE cms_media_derivatives ADD CONSTRAINT fk_cms_media_derivatives_media_id_tenant
 FOREIGN KEY (site_id,media_id) REFERENCES cms_media(site_id,id)
 ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE cms_media_collection_items ADD CONSTRAINT fk_cms_media_collection_items_media_id_tenant
 FOREIGN KEY (site_id,media_id) REFERENCES cms_media(site_id,id)
 ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE cms_media_collection_items ADD CONSTRAINT fk_cms_media_collection_items_collection_id_tenant
 FOREIGN KEY (site_id,collection_id) REFERENCES cms_media_collections(site_id,id)
 ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE cms_form_fields ADD CONSTRAINT fk_cms_form_fields_form_id_tenant
 FOREIGN KEY (site_id,form_id) REFERENCES cms_forms(site_id,id)
 ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE cms_form_submissions ADD CONSTRAINT fk_cms_form_submissions_form_id_tenant
 FOREIGN KEY (site_id,form_id) REFERENCES cms_forms(site_id,id)
 ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE cms_editorial_events ADD CONSTRAINT fk_cms_editorial_events_page_id_tenant
 FOREIGN KEY (site_id,page_id) REFERENCES cms_pages(site_id,id)
 ON UPDATE RESTRICT ON DELETE RESTRICT;
ALTER TABLE publication_jobs ADD CONSTRAINT fk_publication_jobs_page_id_tenant
 FOREIGN KEY (site_id,page_id) REFERENCES cms_pages(site_id,id)
 ON UPDATE RESTRICT ON DELETE RESTRICT;
CREATE FUNCTION cms_prevent_site_id_update() RETURNS trigger LANGUAGE plpgsql AS $scope$
BEGIN IF NEW.site_id IS DISTINCT FROM OLD.site_id THEN
 RAISE EXCEPTION 'cms_tenant_site_id_immutable'; END IF; RETURN NEW; END $scope$;
CREATE TRIGGER guard_cms_pages_site_id BEFORE UPDATE ON cms_pages FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_cms_media_site_id BEFORE UPDATE ON cms_media FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_cms_menu_items_site_id BEFORE UPDATE ON cms_menu_items FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_cms_settings_site_id BEFORE UPDATE ON cms_settings FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_cms_redirects_site_id BEFORE UPDATE ON cms_redirects FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_cms_taxonomies_site_id BEFORE UPDATE ON cms_taxonomies FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_cms_terms_site_id BEFORE UPDATE ON cms_terms FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_cms_term_relationships_site_id BEFORE UPDATE ON cms_term_relationships FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_cms_page_revisions_site_id BEFORE UPDATE ON cms_page_revisions FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_cms_autosaves_site_id BEFORE UPDATE ON cms_autosaves FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_cms_search_index_site_id BEFORE UPDATE ON cms_search_index FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_cms_media_collections_site_id BEFORE UPDATE ON cms_media_collections FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_cms_media_derivatives_site_id BEFORE UPDATE ON cms_media_derivatives FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_cms_media_collection_items_site_id BEFORE UPDATE ON cms_media_collection_items FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_cms_content_types_site_id BEFORE UPDATE ON cms_content_types FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_cms_forms_site_id BEFORE UPDATE ON cms_forms FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_cms_form_fields_site_id BEFORE UPDATE ON cms_form_fields FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_cms_form_submissions_site_id BEFORE UPDATE ON cms_form_submissions FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_cms_reusable_blocks_site_id BEFORE UPDATE ON cms_reusable_blocks FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_cms_block_patterns_site_id BEFORE UPDATE ON cms_block_patterns FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_cms_editorial_events_site_id BEFORE UPDATE ON cms_editorial_events FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_publication_jobs_site_id BEFORE UPDATE ON publication_jobs FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_cms_custom_field_definitions_site_id BEFORE UPDATE ON cms_custom_field_definitions FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
CREATE TRIGGER guard_cms_custom_field_values_site_id BEFORE UPDATE ON cms_custom_field_values FOR EACH ROW EXECUTE FUNCTION cms_prevent_site_id_update();
-- GLOBAL UNIQUE CONSTRAINTS remain: per-site duplicate slugs/keys NOT supported.
-- Other workflows/extensions tables not covered: retain fail-closed global gate.
