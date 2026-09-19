-- VazinCMS 10.10.3: fail-closed direct Visa sale gate and manual-case marker.
INSERT INTO schema_migrations(version) VALUES('10.10.3') ON CONFLICT(version) DO NOTHING;
