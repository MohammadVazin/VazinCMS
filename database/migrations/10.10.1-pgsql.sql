-- VazinCMS 10.10.1: external-runtime delivery marker.
INSERT INTO schema_migrations(version) VALUES('10.10.1') ON CONFLICT(version) DO NOTHING;
