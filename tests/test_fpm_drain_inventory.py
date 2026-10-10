"""Offline fail-closed coverage of Nginx/FPM upstream inventory."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from contextlib import redirect_stdout
from io import StringIO

ROOT=Path(__file__).resolve().parents[1]
SPEC=importlib.util.spec_from_file_location("fpm_drain_inventory", ROOT/"scripts/fpm_drain_inventory.py")
assert SPEC and SPEC.loader
guard=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(guard)


class DrainInventoryTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name)
        self.nginx=self.root/"nginx"
        self.pools=self.root/"pools"
        self.nginx.mkdir()
        self.pools.mkdir()
        (self.nginx/"travel.conf").write_text('server { server_name travel.example.test; '
            'root /var/www/travel/public; location = /health { return 200 "{}"; } '
            'location ~ \\.php$ {fastcgi_pass unix:/run/php/shared.sock;} }\n'
            'ssl_certificate_key /private/root-only/tls-secret.pem;\n')
        (self.nginx/"visa.conf").write_text('server {server_name visa.example.test; '
            'location ~ \\.php$ {fastcgi_pass unix:/run/php/shared.sock;} }\n')
        (self.nginx/"studio.conf").write_text('server {server_name studio.example.test; '
            'location ~ \\.php$ {fastcgi_pass unix:/run/php/studio.sock;} }\n')
        (self.pools/"shared.conf").write_text('[shared]\nlisten = /run/php/shared.sock\npm.max_children = 10\n')
        (self.pools/"studio.conf").write_text('[studio]\nlisten = /run/php/studio.sock\npm.status_path = /fpm_status\npm.max_children = 4\n')

    def test_shared_vhosts_rejected_even_with_healthy_php(self):
        report=guard.inspect(self.nginx,self.pools,["travel.example.test","visa.example.test"])
        self.assertEqual(report["decision"],"NO_GO")
        for site in report["sites"]:
            self.assertIn("fastcgi_backend_shared_with_other_vhosts",site["blockers"])
            self.assertFalse(site["drain_verified"])

    def test_dedicated_pool_is_not_an_authorized_drain(self):
        report=guard.inspect(self.nginx,self.pools,["studio.example.test"])
        site=report["sites"][0]
        self.assertEqual(site["upstreams"][0]["pool_name"],"studio")
        self.assertTrue(site["upstreams"][0]["status_path_configured"])
        self.assertFalse(site["drain_verified"])
        self.assertFalse(report["release_authorized"])

    def test_no_visible_php_upstream_fails_closed(self):
        (self.nginx/"static.conf").write_text('server {server_name static.example.test; root /srv/static;}\n')
        site=guard.inspect(self.nginx,self.pools,["static.example.test"])["sites"][0]
        self.assertIn("no_fastcgi_upstream_visible_in_static_vhost",site["blockers"])

    def test_missing_directory_fails_closed(self):
        with self.assertRaisesRegex(ValueError,"nginx_directory_unavailable"):
            guard.inspect(self.root/"absent",self.pools,["visa.example.test"])

    def test_invalid_duplicate_or_blank_hosts_rejected(self):
        for invalid in ([],["visa.example.test","visa.example.test"],["/etc/passwd"]):
            with self.assertRaisesRegex(ValueError,"requested_host_list_invalid"):
                guard.inspect(self.nginx,self.pools,invalid)

    def test_sensitive_directives_are_never_exposed(self):
        report=guard.inspect(self.nginx,self.pools,["travel.example.test"])
        encoded=json.dumps(report)
        self.assertNotIn("tls-secret.pem",encoded)
        self.assertNotIn("private/root-only",encoded)
        self.assertNotIn('password',encoded)

    def test_cli_always_no_go(self):
        output=StringIO()
        with redirect_stdout(output):
            code=guard.main(["--nginx-dir",str(self.nginx),"--fpm-dir",str(self.pools),
                             "--hosts","studio.example.test"])
        self.assertEqual(code,70)
        self.assertEqual(json.loads(output.getvalue())["decision"],"NO_GO")


if __name__=="__main__":
    unittest.main()
