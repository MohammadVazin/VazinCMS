ALTER TABLE user_identities ADD COLUMN IF NOT EXISTS profile_revision BIGINT;
DO $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM pg_constraint WHERE conname='user_identities_profile_revision_check' AND conrelid='user_identities'::regclass) THEN
  ALTER TABLE user_identities ADD CONSTRAINT user_identities_profile_revision_check CHECK(profile_revision IS NULL OR profile_revision BETWEEN 1 AND 9007199254740991);
 END IF;
END $$;
INSERT INTO schema_migrations(version) VALUES('10.6.2') ON CONFLICT(version) DO NOTHING;
