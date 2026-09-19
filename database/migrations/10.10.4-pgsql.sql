-- VazinCMS 10.10.4: manual eVisa active-theme compatibility hotfix marker.
INSERT INTO schema_migrations(version) VALUES('10.10.4') ON CONFLICT(version) DO NOTHING;
