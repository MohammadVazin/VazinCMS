UPDATE products SET current_version='4.5.0' WHERE slug='vazincms';
INSERT INTO schema_migrations(version) VALUES('4.5.0') ON CONFLICT(version) DO NOTHING;
