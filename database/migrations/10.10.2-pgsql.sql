-- VazinCMS 10.10.2: active public-theme UI delivery marker.
INSERT INTO schema_migrations(version) VALUES('10.10.2') ON CONFLICT(version) DO NOTHING;
