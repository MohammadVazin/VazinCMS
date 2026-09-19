-- VazinCMS 10.9.1 is an application-level hardening release.
-- Existing 10.9.0 appointment tables already contain the required schema.
INSERT INTO schema_migrations(version) VALUES('10.9.1') ON CONFLICT(version) DO NOTHING;
