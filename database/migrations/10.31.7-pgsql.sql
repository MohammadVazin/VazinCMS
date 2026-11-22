-- REVIEW CANDIDATE ONLY. Intended migration version 10.31.7.
-- Source SHA256: 9cc19cfe50836ef379d0f7177d0e771f012a9747db719b9402dd3e8ce9627834
-- Production execution forbidden pending signed release and DR approval.
-- The official migrate.php owns BEGIN/COMMIT; no nested transaction.
-- VazinCMS Phase10 PostgreSQL SERIAL sequence repair — proposal only.
-- Never execute on production without validated backup, owner/domain map,
-- signed migration ancestry and exclusive migration maintenance window.
--
-- Official cms_sites bootstrap inserts id=1 explicitly; SERIAL sequence may
-- still have is_called=false,last_value=1, so next implicit INSERT collides.
-- This operation ONLY advances the sequence, never moves it backward.
-- Re-entrant, atomic, and protected against concurrent site INSERT.
LOCK TABLE cms_sites IN ACCESS EXCLUSIVE MODE;
DO $phase10$
DECLARE
  seq_name TEXT;
  max_site BIGINT;
  last_seq BIGINT;
  was_called BOOLEAN;
  new_seq BIGINT;
BEGIN
  seq_name:=pg_get_serial_sequence('cms_sites','id');
  IF seq_name IS NULL THEN
    RAISE EXCEPTION 'cms_sites_is_missing_serial_sequence';
  END IF;
  SELECT COALESCE(MAX(id),0) INTO max_site FROM cms_sites;
  IF max_site<1 THEN
    RAISE EXCEPTION 'cms_sites_missing_default_site';
  END IF;
  EXECUTE format('SELECT last_value,is_called FROM %s',seq_name)
    INTO last_seq,was_called;
  new_seq:=GREATEST(max_site,last_seq);
  PERFORM setval(seq_name::regclass,new_seq,
      was_called OR new_seq=max_site);
  IF (SELECT COUNT(*) FROM cms_sites WHERE site_key='default' AND id=1)<>1 THEN
    RAISE EXCEPTION 'cms_default_site_id_mismatch';
  END IF;
END $phase10$;
