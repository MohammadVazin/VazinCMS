-- VazinCMS 10.10.5: Travel agency request active-theme render hotfix marker.
INSERT INTO schema_migrations(version) VALUES('10.10.5') ON CONFLICT(version) DO NOTHING;
