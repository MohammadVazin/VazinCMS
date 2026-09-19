-- VazinCMS 10.10.9: tenant-demo preflight tooling marker.
INSERT INTO schema_migrations(version) VALUES('10.10.9') ON CONFLICT(version) DO NOTHING;
