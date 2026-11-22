-- REVIEW CANDIDATE ONLY. Intended migration version 10.31.1.
-- Source SHA256: 3369cde19e22b496b3c3b1b573be68da7bebbbdc3adf5252f67cfb8bc3d94f4b
-- Production execution forbidden pending signed release and DR approval.
-- The official migrate.php owns BEGIN/COMMIT; no nested transaction.
-- VazinCMS tenant UNIQUE constraints — PostgreSQL 17 proposal, NOT production.
-- Apply on a disposable schema with proposed 10.31.0 site_id migration first.
-- Owner/site binding, restores, per-site API/security and concurrency remain gates.
-- No DDL is installed in the official migration directory.
DO $proposal$
DECLARE
  item RECORD;
  old_constraint TEXT;
  found_count INTEGER;
  colnames TEXT[];
  tbl_oid OID;
  site_column_notnull BOOLEAN;
BEGIN
  IF (SELECT COUNT(*) FROM cms_sites) <> 1
     OR (SELECT COUNT(*) FROM cms_sites WHERE site_key='default' AND status='active') <> 1 THEN
    RAISE EXCEPTION 'tenant_unique_requires_exactly_one_default_site';
  END IF;

  FOR item IN SELECT * FROM (VALUES
    ('cms_pages', ARRAY['slug','locale']::TEXT[], 'u', 'cms_pages_site_slug_locale_unique', 'UNIQUE(site_id,slug,locale)'),
    ('cms_settings', ARRAY['setting_key']::TEXT[], 'p', 'cms_settings_pkey', 'PRIMARY KEY(site_id,setting_key)'),
    ('cms_redirects', ARRAY['source_path']::TEXT[], 'u', 'cms_redirects_site_source_unique', 'UNIQUE(site_id,source_path)'),
    ('cms_media', ARRAY['stored_name']::TEXT[], 'u', 'cms_media_site_stored_unique', 'UNIQUE(site_id,stored_name)'),
    ('cms_taxonomies', ARRAY['taxonomy_key']::TEXT[], 'u', 'cms_taxonomies_site_key_unique', 'UNIQUE(site_id,taxonomy_key)'),
    ('cms_content_types', ARRAY['content_key']::TEXT[], 'u', 'cms_content_types_site_key_unique', 'UNIQUE(site_id,content_key)'),
    ('cms_forms', ARRAY['form_key']::TEXT[], 'u', 'cms_forms_site_key_unique', 'UNIQUE(site_id,form_key)'),
    ('cms_reusable_blocks', ARRAY['block_key']::TEXT[], 'u', 'cms_reusable_blocks_site_key_unique', 'UNIQUE(site_id,block_key)'),
    ('cms_block_patterns', ARRAY['pattern_key']::TEXT[], 'u', 'cms_block_patterns_site_key_unique', 'UNIQUE(site_id,pattern_key)'),
    ('cms_media_collections', ARRAY['collection_key']::TEXT[], 'u', 'cms_media_collections_site_key_unique', 'UNIQUE(site_id,collection_key)'),
    ('cms_custom_field_definitions', ARRAY['field_key']::TEXT[], 'u', 'cms_custom_fields_site_key_unique', 'UNIQUE(site_id,field_key)')
  ) AS mapping(table_name,old_columns,kind,new_name,new_definition)
  LOOP
    tbl_oid := to_regclass(format('%I.%I', current_schema(), item.table_name));
    IF tbl_oid IS NULL THEN
      RAISE EXCEPTION 'tenant_unique_missing_table: %', item.table_name;
    END IF;

    SELECT a.attnotnull INTO site_column_notnull
    FROM pg_attribute a
    WHERE a.attrelid=tbl_oid AND a.attname='site_id' AND NOT a.attisdropped;
    IF site_column_notnull IS DISTINCT FROM TRUE THEN
      RAISE EXCEPTION 'tenant_unique_missing_nonnull_site_column: %', item.table_name;
    END IF;

    SELECT COUNT(*), MIN(c.conname)
    INTO found_count, old_constraint
    FROM pg_constraint c
    WHERE c.conrelid=tbl_oid
      AND c.contype=item.kind::"char"
      AND ARRAY(
        SELECT a.attname::TEXT
        FROM unnest(c.conkey) WITH ORDINALITY AS k(attnum,ordinal)
        JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=k.attnum
        ORDER BY k.ordinal
      )=item.old_columns;
    IF found_count<>1 OR old_constraint IS NULL THEN
      RAISE EXCEPTION 'tenant_unique_unexpected_old_constraint: % count=%',item.table_name,found_count;
    END IF;

    EXECUTE format('ALTER TABLE %I DROP CONSTRAINT %I',item.table_name,old_constraint);
    EXECUTE format('ALTER TABLE %I ADD CONSTRAINT %I %s',
                   item.table_name,item.new_name,item.new_definition);
  END LOOP;

  -- The global SHA uniqueness is a separate partial index, not a table constraint.
  SELECT COUNT(*) INTO found_count
  FROM pg_index i JOIN pg_class idx ON idx.oid=i.indexrelid
  WHERE i.indrelid=to_regclass(format('%I.cms_media',current_schema()))
    AND idx.relname='idx_cms_media_sha256'
    AND i.indisunique AND i.indpred IS NOT NULL
    AND (
      SELECT string_agg(a.attname,',' ORDER BY k.ordinal)
      FROM unnest(i.indkey) WITH ORDINALITY AS k(attnum,ordinal)
      JOIN pg_attribute a ON a.attrelid=i.indrelid AND a.attnum=k.attnum
    )='sha256';
  IF found_count<>1 THEN
    RAISE EXCEPTION 'tenant_unique_media_sha_index_unexpected';
  END IF;
END $proposal$;

DROP INDEX idx_cms_media_sha256;
CREATE UNIQUE INDEX idx_cms_media_sha256 ON cms_media(site_id,sha256) WHERE sha256<>'';
