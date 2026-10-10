"""Offline contract tests: static FPM inventory must never authorize rollout."""
from __future__ import annotations

import contextlib
import importlib.util
from io import StringIO
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("fpm_drain_inventory", ROOT / "scripts/fpm_drain_inventory.py")
assert SPEC is not None and SPEC.loader is not None
audit = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(audit)


class DrainInventoryTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        base = Path(self.temp.name)
        self.nginx = base / "nginx"
        self.pools = base / "pools"
        self.nginx.mkdir()
        self.pools.mkdir()
        (self.nginx / "shared.conf").write_text("""
server { server_name travel.example.test; root /var/www/travel/public;
  location ~ \\.php$ { fastcgi_pass unix:/run/php/www.sock; }
}
server { server_name visa.example.test; root /var/www/visa/public;
  location ~ \\.php$ { fastcgi_pass unix:/run/php/www.sock; }
}
# server { server_name fake.example.test; fastcgi_pass unix:/run/php/fake.sock; }
""")
        (self.nginx / "studio.conf").write_text("""
server { server_name studio.example.test; root /var/www/studio/public;
 location = /admin { include /etc/nginx/fastcgi_params; fastcgi_pass unix:/run/php/studio.sock; }
}
""")
        (self.pools / "www.conf").write_text(
            "[www]\nlisten = /run/php/www.sock\npm.max_children = 10\n;pm.status_path=/commented\n"
        )
        (self.pools / "studio.conf").write_text(
            "[studio]\nlisten = /run/php/studio.sock\npm.status_path = /_status\n"
            "pm.status_listen = /run/php/studio-status.sock\n"
        )

    def test_two_vhosts_in_one_file_share_one_backend(self):
        report = audit.inspect(self.nginx, self.pools, ["travel.example.test", "visa.example.test"])
        self.assertEqual(report["decision"], "NO_GO")
        self.assertFalse(report["release_authorized"])
        for site in report["sites"]:
            self.assertIn("fpm_listener_shared_across_vhosts", site["blockers"])
            self.assertIn("inflight_requests_unobserved", site["blockers"])
            self.assertEqual(site["backends"][0]["observed_host_count"], 2)

    def test_distinct_servers_in_one_file_are_not_conflated(self):
        (self.nginx / "shared.conf").write_text(
            "server { server_name travel.example.test; fastcgi_pass unix:/run/php/www.sock; }\n"
            "server { server_name visa.example.test; fastcgi_pass unix:/run/php/studio.sock; }\n"
        )
        mapping = audit.nginx_hosts(self.nginx)
        self.assertEqual(mapping["travel.example.test"]["upstreams"], {"unix:/run/php/www.sock"})
        self.assertEqual(mapping["visa.example.test"]["upstreams"], {"unix:/run/php/studio.sock"})

    def test_comments_and_quotes_are_not_phantom_hosts(self):
        fixture = '# server {server_name hidden.example.test;}\n' \
                  'server {set $test "server {not a block} # string";server_name real.example.test;}\n'
        self.assertEqual(len(audit.nginx_server_blocks(fixture)), 1)
        self.assertNotIn("hidden.example.test", audit.nginx_server_blocks(fixture)[0])

    def test_unclosed_server_block_is_rejected(self):
        with self.assertRaisesRegex(ValueError, "nginx_server_block_not_closed"):
            audit.nginx_server_blocks("server {server_name a.example.test;")

    def test_dedicated_fpm_with_status_still_no_go(self):
        r = audit.inspect(self.nginx, self.pools, ["studio.example.test"])
        site = r["sites"][0]
        self.assertTrue(site["backends"][0]["status_path_configured"])
        self.assertTrue(site["backends"][0]["status_listener_configured"])
        self.assertFalse(site["drain_verified"])
        self.assertEqual(r["decision"], "NO_GO")

    def test_status_config_commented_out_is_not_accepted(self):
        pool = audit.fpm_pools(self.pools)["/run/php/www.sock"]
        self.assertFalse(pool["status_path_configured"])
        self.assertFalse(pool["status_listener_configured"])

    def test_unresolved_nginx_include_flagged(self):
        r = audit.inspect(self.nginx, self.pools, ["studio.example.test"])
        self.assertIn("nginx_includes_not_expanded", r["sites"][0]["blockers"])

    def test_missing_backend_never_becomes_verified(self):
        (self.nginx / "static.conf").write_text(
            "server {server_name static.example.test;root /srv/static;}"
        )
        site = audit.inspect(self.nginx, self.pools, ["static.example.test"])["sites"][0]
        self.assertIn("fastcgi_upstream_not_visible", site["blockers"])
        self.assertFalse(site["drain_verified"])

    def test_duplicate_and_invalid_host_filters_rejected(self):
        for hosts in ([], ["visa.example.test", "visa.example.test"], ["/etc/shadow"]):
            with self.assertRaisesRegex(ValueError, "host_filter_invalid"):
                audit.inspect(self.nginx, self.pools, hosts)

    def test_missing_config_directory_rejected(self):
        with self.assertRaisesRegex(ValueError, "nginx_dir_not_found"):
            audit.inspect(self.nginx / "missing", self.pools, ["travel.example.test"])

    def test_does_not_expose_sensitive_unrelated_config(self):
        (self.nginx / "studio.conf").write_text(
            'server {server_name studio.example.test;ssl_certificate_key /private/password-key.pem; '
            'fastcgi_pass unix:/run/php/studio.sock;}'
        )
        report = audit.inspect(self.nginx, self.pools, ["studio.example.test"])
        encoded = json.dumps(report)
        self.assertNotIn("password-key", encoded)
        self.assertNotIn("/private/", encoded)

    def test_cli_always_exit_70_even_with_dedicated_pool(self):
        out = StringIO()
        with contextlib.redirect_stdout(out):
            code = audit.main([
                "--nginx-dir", str(self.nginx), "--fpm-dir", str(self.pools),
                "--hosts", "studio.example.test"
            ])
        self.assertEqual(code, 70)
        self.assertEqual(json.loads(out.getvalue())["decision"], "NO_GO")


if __name__ == "__main__":
    unittest.main()
