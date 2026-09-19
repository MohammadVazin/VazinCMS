ALTER TABLE user_identities ADD COLUMN profile_revision INTEGER CHECK(profile_revision IS NULL OR (typeof(profile_revision)='integer' AND profile_revision BETWEEN 1 AND 9007199254740991));
INSERT OR IGNORE INTO schema_migrations(version) VALUES('10.6.2');
