-- VazinCMS 10.9.2 adds the managed-site bootstrap contract outside the schema.
INSERT INTO schema_migrations(version) VALUES('10.9.2') ON CONFLICT(version) DO NOTHING;
