"""Offline checks for intentionally non-authorizing FPM status samples."""
from __future__ import annotations

import contextlib
from io import StringIO
import importlib.util
import os
from pathlib import Path
import socket
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
SPEC=importlib.util.spec_from_file_location("fpm_status_evidence",ROOT/"scripts/fpm_status_evidence.py")
assert SPEC and SPEC.loader
audit=importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(audit)


def payload(active=0,idle=1,queue=0,accepted=7,pool="site"):
    import json
    return ("Content-Type: application/json;charset=UTF-8\r\n\r\n"+
            json.dumps({"pool":pool,"active processes":active,"idle processes":idle,
                        "total processes":active+idle,"listen queue":queue,"accepted conn":accepted}))


class StatusEvidenceTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.path=Path(self.temp.name)/"status.sock"

    def test_parse_realistic_json(self):
        row=audit.parse_status(payload(),"site")
        self.assertEqual(row["active"],0)
        self.assertEqual(row["listen_queue"],0)
        self.assertEqual(row["accepted_conn"],7)

    def test_pool_identity_mismatch_is_rejected(self):
        with self.assertRaisesRegex(audit.EvidenceError,"pool_identity_mismatch"):
            audit.parse_status(payload(pool="other"),"site")

    def test_malformed_or_untyped_counts_are_rejected(self):
        samples=[payload().replace('"active processes": 0','"active processes": -1'),
                 payload().replace('"active processes": 0','"active processes": true'),
                 payload().replace('"listen queue": 0','"listen queue": "0"')]
        for raw in samples:
            with self.subTest(raw=raw[-75:]),self.assertRaisesRegex(audit.EvidenceError,"status_counter_invalid"):
                audit.parse_status(raw,"site")

    def test_bad_json_or_wrong_content_type(self):
        for raw in ("Content-Type: text/plain\r\n\r\n{}", "Content-Type: application/json\r\n\r\nnot-json"):
            with self.assertRaises(audit.EvidenceError):
                audit.parse_status(raw,"site")

    def test_bad_status_code(self):
        with self.assertRaisesRegex(audit.EvidenceError,"status_endpoint_not_ok"):
            audit.parse_status("Status: 503 Service Unavailable\r\n"+payload(),"site")

    def test_too_large_payload(self):
        with self.assertRaisesRegex(audit.EvidenceError,"too_large"):
            audit.parse_status("X"*17000,"site")

    def test_socket_must_not_be_regular_file(self):
        self.path.write_text("not a socket")
        with self.assertRaisesRegex(audit.EvidenceError,"status_target_not_socket"):
            audit.verify_unix_socket(self.path)

    def test_socket_symlink_rejected(self):
        with socket.socket(socket.AF_UNIX) as sock:
            sock.bind(str(self.path))
            symlink=self.path.parent/"alias.sock"
            symlink.symlink_to(self.path)
            with self.assertRaisesRegex(audit.EvidenceError,"symlinked"):
                audit.verify_unix_socket(symlink)

    def test_valid_unix_socket_file(self):
        with socket.socket(socket.AF_UNIX) as sock:
            sock.bind(str(self.path))
            audit.verify_unix_socket(self.path)

    def test_busy_worker_fails_quiet_but_decision_always_no_go(self):
        data=[{"active":1,"idle":0,"total":1,"listen_queue":0,"accepted_conn":3},
              {"active":0,"idle":1,"total":1,"listen_queue":0,"accepted_conn":3},
              {"active":0,"idle":1,"total":1,"listen_queue":0,"accepted_conn":3}]
        data=[dict(x,pool="site") for x in data]
        with patch.object(audit,"sample_once",side_effect=data),patch.object(audit.time,"sleep"):
            result=audit.sample_series(self.path,"site",3,0.1)
        self.assertFalse(result["observed_quiet"])
        self.assertFalse(result["release_authorized"])
        self.assertEqual(result["decision"],"NO_GO")

    def test_idle_readings_can_be_observed_quiet_but_not_authorized(self):
        row={"active":0,"idle":1,"total":1,"listen_queue":0,"accepted_conn":3,"pool":"site"}
        with patch.object(audit,"sample_once",return_value=row),patch.object(audit.time,"sleep"):
            result=audit.sample_series(self.path,"site",4,0.1)
        self.assertTrue(result["observed_quiet"])
        self.assertFalse(result["drain_verified"])
        self.assertFalse(result["background_jobs_verified"])
        self.assertEqual(result["decision"],"NO_GO")

    def test_accepted_count_changes_not_steady(self):
        data=[{"active":0,"idle":1,"total":1,"listen_queue":0,"accepted_conn":i,"pool":"site"}
              for i in (3,3,4)]
        with patch.object(audit,"sample_once",side_effect=data),patch.object(audit.time,"sleep"):
            result=audit.sample_series(self.path,"site",3,0.1)
        self.assertFalse(result["observed_quiet"])
        self.assertEqual(result["accepted_connection_delta"],1)

    def test_invalid_sampling_limits_fails_closed(self):
        for n,interval in [(0,.5),(2,.5),(31,.5),(3,.05),(3,4)]:
            with self.subTest(n=n,interval=interval),self.assertRaisesRegex(audit.EvidenceError,"settings_invalid"):
                audit.sample_series(self.path,"site",n,interval)

    def test_cli_unavailable_status_is_no_go_without_path_leak(self):
        output=StringIO()
        with contextlib.redirect_stdout(output):
            exit_code=audit.main(["--socket",str(self.path),"--pool","site"])
        self.assertEqual(exit_code,70)
        self.assertNotIn(str(self.path),output.getvalue())
        self.assertIn('"decision":"NO_GO"',output.getvalue())

    def test_no_env_secrets_transferred_to_cgi(self):
        fake=type("Result",(),{"returncode":0,"stdout":payload(),"stderr":""})()
        with socket.socket(socket.AF_UNIX) as sock:
            sock.bind(str(self.path))
            with patch.object(audit.subprocess,"run",return_value=fake) as run:
                audit.sample_once(self.path,"site")
                env=run.call_args.kwargs["env"]
                self.assertEqual(env["QUERY_STRING"],"json")
                self.assertNotIn("DB_PASSWORD",env)
                self.assertNotIn("API_KEY",env)
                self.assertEqual(run.call_args.args[0][0],"/usr/bin/cgi-fcgi")


if __name__=="__main__":
    unittest.main()
