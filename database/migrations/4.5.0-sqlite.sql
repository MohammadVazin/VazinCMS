UPDATE products SET current_version='4.5.0' WHERE slug='vazincms';
INSERT OR IGNORE INTO schema_migrations(version) VALUES('4.5.0');
