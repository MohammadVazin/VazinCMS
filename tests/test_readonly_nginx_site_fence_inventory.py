"""Synthetic contract tests for privacy-preserving, read-only Nginx/FPM audit."""
from __future__ import annotations

import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
from io import StringIO

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "scripts/readonly_nginx_site_fence_inventory.py"
SPEC = importlib.util.spec_from_file_location("readonly_nginx_site_fence_inventory", SOURCE)
assert SPEC is not None and SPEC.loader is not None
audit = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(audit)


class NginxReadOnlyInventoryTests(unittest.TestCase):
    SECRET = "SYNTHETIC_PRIVATE_HEADER_DO_NOT_DISCLOSE_123"

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.nginx = self.base / "nginx"
        self.fpm = self.base / "fpm"
        for name in ("sites-enabled", "conf.d", "snippets"):
            (self.nginx / name).mkdir(parents=True)
        self.fpm.mkdir()
        (self.nginx / "nginx.conf").write_text(
            "events {} http { include conf.d/*.conf; include sites-enabled/*; }\n",
            encoding="utf-8",
        )
        (self.nginx / "conf.d" / "rates.conf").write_text("map $a $b { default 1; }\n")
        self.backend = {
            "CMS": "unix:/run/php/vazincms-core.sock",
            "VISA": "unix:/run/php/php8.5-fpm.sock",
            "STUDIO": "unix:/run/php/vazincms-studio.sock",
            "TRAVEL": "unix:/run/php/php8.5-fpm.sock",
            "RUSSIAFA": "unix:/run/php/vazincms-russiafa.sock",
        }
        for label, (entry, domain) in audit.SITES.items():
            self.write_site(label, f"""
server {{
    server_name {domain};
    # Ignored /__vazin_cms_health X-Vazin-Release-Fence
    location / {{ try_files $uri /index.php; }}
    location ~ [.]php$ {{ fastcgi_pass {self.backend[label]}; }}
    add_header X-Private-Synthetic "{self.SECRET}";
}}
""")
        for key, path in audit.KNOWN_SOCKETS.items():
            (self.fpm / (path + ".conf")).write_text(
                "[pool]\nlisten = /run/php/" + key + "\n", encoding="utf-8")

    def write_site(self, label, content):
        entry = audit.SITES[label][0]
        (self.nginx / "sites-enabled" / entry).write_text(content, encoding="utf-8")

    def summary(self):
        return audit.audit(self.nginx, self.fpm)

    def test_shared_php85_across_visa_and_travel(self):
        report = self.summary()
        self.assertEqual(report["php85_shared_site_labels"], ["TRAVEL", "VISA"])
        self.assertEqual(report["production"], "NO_GO")
        self.assertTrue(all(s["all_routes_fenced"] == "NOT_PROVEN" for s in report["sites"]))

    def test_no_raw_secret_ip_or_config_file_contents_in_report(self):
        report = self.summary()
        text = json.dumps(report)
        self.assertNotIn(self.SECRET, text)
        self.assertNotIn(self.temp.name, text)
        self.assertNotIn("127.0.0.1", text)
        self.assertNotIn("X-Private-Synthetic", text)
        self.assertNotIn("listen = /run/php", text)

    def test_comments_are_stripped_and_no_false_fence_receipt(self):
        cms = next(x for x in self.summary()["sites"] if x["site"] == "CMS")
        self.assertFalse(cms["qa_literal_found"])
        self.assertFalse(cms["fence_header_literal_found"])
        self.assertEqual(cms["location_block_count_approx"], 2)

    def test_internal_include_is_scanned_but_still_no_go(self):
        snippet = self.nginx / "snippets" / "fence.conf"
        snippet.write_text("add_header X-Vazin-Release-Fence fake; location = /__vazin_cms_health { return 503; }")
        self.write_site("CMS", f"server {{ server_name cms.vazin.online; include {snippet}; }}")
        s = next(x for x in self.summary()["sites"] if x["site"] == "CMS")
        self.assertTrue(s["qa_literal_found"])
        self.assertTrue(s["fence_header_literal_found"])
        self.assertEqual(s["files_examined"], 2)
        self.assertEqual(s["all_routes_fenced"], "NOT_PROVEN")

    def test_external_include_is_not_read_or_claimed_scanned(self):
        out = self.base / "outside" / "not-accessible.conf"
        out.parent.mkdir()
        out.write_text("location / { return 200 'SECRET_NOT_A_SITE'; }")
        self.write_site("CMS", f"server {{ server_name cms.vazin.online; include {out}; }}")
        s = next(x for x in self.summary()["sites"] if x["site"] == "CMS")
        self.assertEqual(s["unexamined_includes"], 1)
        self.assertEqual(s["files_examined"], 1)
        self.assertNotIn("SECRET_NOT_A_SITE", json.dumps(self.summary()))

    def test_unknown_socket_or_dynamic_backend_is_only_classified(self):
        self.write_site("CMS", """
server { server_name cms.vazin.online;
 location ~ [.]php$ { fastcgi_pass $UPSTREAM_INTERNAL_SECRET; }
}""")
        s = next(x for x in self.summary()["sites"] if x["site"] == "CMS")
        self.assertEqual(s["fastcgi_backend_classes"], ["dynamic_backend"])
        self.assertNotIn("UPSTREAM_INTERNAL_SECRET", json.dumps(self.summary()))

    def test_realip_directive_counts_but_never_values(self):
        (self.nginx / "conf.d" / "trusted.conf").write_text(
            "set_real_ip_from 192.0.2.0/24; real_ip_header X-Forwarded-For;\n"
        )
        report = self.summary()
        self.assertEqual(report["nginx_global_realip_directive_counts"]["set_real_ip_from"], 1)
        self.assertEqual(report["nginx_global_realip_directive_counts"]["real_ip_header"], 1)
        self.assertNotIn("192.0.2.0/24", json.dumps(report))

    def test_quoted_hash_kept_but_ordinary_comment_dropped(self):
        source = 'add_header Test "a#b"; # include /tmp/unrelated;\n include "snippets/a.conf";'
        cleaned = audit.remove_nginx_comments(source)
        self.assertIn('"a#b"', cleaned)
        self.assertNotIn("/tmp/unrelated", cleaned)
        self.assertEqual(audit.includes_for(cleaned), ["snippets/a.conf"])

    def test_pool_status_path_is_configuration_only_not_drain_receipt(self):
        (self.fpm / "shared_php85.conf").write_text(
            "[www]\nlisten=/run/php/php8.5-fpm.sock\npm.status_path=/__internal_fpm_status\n"
        )
        report = self.summary()
        self.assertEqual(report["fpm_pool_status_configuration"]["shared_php85"],
                         "status_path_only")
        self.assertEqual(report["production"], "NO_GO")

    def test_duplicate_pool_listener_is_flagged(self):
        (self.fpm / "other.conf").write_text(
            "[another]\nlisten=/run/php/php8.5-fpm.sock\n", encoding="utf-8")
        report = self.summary()
        self.assertEqual(report["fpm_pool_status_configuration"]["shared_php85"],
                         "duplicate_pool_listeners")
        self.assertEqual(report["production"], "NO_GO")

    def test_missing_expected_vhost_fails_closed_without_path_leak(self):
        (self.nginx / "sites-enabled" / "cms.vazin.online").unlink()
        with self.assertRaises(audit.ScanIncomplete):
            self.summary()
        buf = StringIO()
        with patch("sys.stdout", buf):
            code = audit.main(["--nginx-root", str(self.nginx),
                               "--fpm-pool-root", str(self.fpm)])
        self.assertEqual(code, 70)
        output = buf.getvalue()
        self.assertIn('"result":"INCOMPLETE"', output)
        self.assertNotIn(str(self.nginx), output)

    def test_symlink_to_outside_root_never_read(self):
        path = self.nginx / "sites-enabled" / "cms.vazin.online"
        path.unlink()
        external = self.base / "outside_config"
        external.write_text("server { # must-not-leak\n add_header Secret REAL_SECRET; }")
        path.symlink_to(external)
        with self.assertRaises(audit.ScanIncomplete):
            self.summary()

    def test_no_nginx_invocation_or_service_mutation_in_source(self):
        source = SOURCE.read_text(encoding="utf-8")
        for forbidden in ("subprocess.Popen", "subprocess.run", "systemctl", "nginx -T",
                          "nginx -s reload", "write_text(", "remove(", "unlink("):
            self.assertNotIn(forbidden, source)
        self.assertIn('"production": "NO_GO"', source)


if __name__ == "__main__":
    unittest.main()
