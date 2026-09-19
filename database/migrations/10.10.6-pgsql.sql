-- VazinCMS 10.10.6: operational agency inbox and read-only alert readiness marker.
INSERT INTO schema_migrations(version) VALUES('10.10.6') ON CONFLICT(version) DO NOTHING;
