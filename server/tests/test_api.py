#!/usr/bin/env python3
"""Integration checks for the standalone BERVEL questionnaire API."""

from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import socket
import sqlite3
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[2]
API_PATH = "/server/bervel-questionnaire.php"
ALLOWED_ORIGIN = "http://127.0.0.1:8899"
ADMIN_TOKEN = "integration-test-admin-token"
QUESTION_IDS = [
    "C01", "C02", "C03", "C04", "C05", "C06", "C07", "C08", "C09",
    "C14", "C15", "C17", "C18", "C19", "C20", "C21", "C22", "C23",
    "C24", "C25", "C26", "C27", "C29",
]
PREFILLED_IDS = {
    "C01", "C02", "C04", "C06", "C07", "C08", "C09", "C14", "C15",
    "C17", "C18", "C19", "C20", "C21", "C22", "C24", "C25",
}


def free_port() -> int:
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return int(sock.getsockname()[1])


def payload(submission_id: str | None = None) -> dict:
    return {
        "schemaVersion": "bervel-questionnaire/v1",
        "questionnaireVersion": "2.9",
        "submissionId": submission_id or str(uuid.uuid4()),
        "website": "",
        "answers": [
            {
                "id": question_id,
                "answer": f"Интеграционный ответ для {question_id}",
                "confirmed": question_id in PREFILLED_IDS,
            }
            for question_id in QUESTION_IDS
        ],
    }


class ApiTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.tempdir = tempfile.TemporaryDirectory(prefix="bervel-api-")
        cls.temp = Path(cls.tempdir.name)
        cls.database = cls.temp / "data" / "answers.sqlite3"
        cls.config = cls.temp / "config.php"
        token_hash = hashlib.sha256(ADMIN_TOKEN.encode()).hexdigest()
        cls.config.write_text(
            "<?php\nreturn [\n"
            f"  'database_path' => {json.dumps(str(cls.database))},\n"
            f"  'admin_token_hash' => '{token_hash}',\n"
            f"  'allowed_origins' => ['{ALLOWED_ORIGIN}'],\n"
            "];\n",
            encoding="utf-8",
        )
        cls.port = free_port()
        cls.base_url = f"http://127.0.0.1:{cls.port}{API_PATH}"
        env = os.environ.copy()
        env["BERVEL_CONFIG_PATH"] = str(cls.config)
        cls.server = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{cls.port}", "-t", str(ROOT)],
            env=env,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        deadline = time.time() + 5
        while time.time() < deadline:
            try:
                cls.request("OPTIONS", origin=ALLOWED_ORIGIN)
                break
            except OSError:
                time.sleep(0.05)
        else:
            raise RuntimeError("PHP test server did not start")

    @classmethod
    def tearDownClass(cls) -> None:
        cls.server.terminate()
        cls.server.wait(timeout=5)
        cls.tempdir.cleanup()

    @classmethod
    def request(
        cls,
        method: str,
        body: dict | None = None,
        token: str | None = None,
        origin: str | None = None,
    ) -> tuple[int, dict | None]:
        data = None if body is None else json.dumps(body, ensure_ascii=False).encode()
        headers = {}
        if body is not None:
            headers["Content-Type"] = "application/json"
        if token is not None:
            headers["Authorization"] = f"Bearer {token}"
        if origin is not None:
            headers["Origin"] = origin
        request = urllib.request.Request(cls.base_url, data=data, headers=headers, method=method)
        try:
            with urllib.request.urlopen(request, timeout=3) as response:
                raw = response.read()
                return response.status, json.loads(raw) if raw else None
        except urllib.error.HTTPError as error:
            raw = error.read()
            return error.code, json.loads(raw) if raw else None

    def test_submission_is_validated_saved_and_read_back(self) -> None:
        invalid = payload()
        invalid["answers"] = invalid["answers"][:-1]
        status, response = self.request("POST", invalid, origin=ALLOWED_ORIGIN)
        self.assertEqual(status, 422)
        self.assertEqual(response["error"]["code"], "answers_incomplete")

        submission = payload()
        status, response = self.request("POST", submission, origin=ALLOWED_ORIGIN)
        self.assertEqual(status, 201)
        self.assertTrue(response["ok"])
        self.assertEqual(response["data"]["answerCount"], 23)
        self.assertFalse(response["data"]["duplicate"])

        status, response = self.request("POST", submission, origin=ALLOWED_ORIGIN)
        self.assertEqual(status, 200)
        self.assertTrue(response["data"]["duplicate"])

        status, response = self.request("GET", token="wrong", origin=ALLOWED_ORIGIN)
        self.assertEqual(status, 401)
        self.assertEqual(response["error"]["code"], "unauthorized")

        status, response = self.request("GET", token=ADMIN_TOKEN, origin=ALLOWED_ORIGIN)
        self.assertEqual(status, 200)
        self.assertEqual(response["data"]["count"], 1)
        saved = response["data"]["submissions"][0]
        self.assertEqual(saved["id"], submission["submissionId"])
        self.assertEqual(saved["answerCount"], 23)
        self.assertEqual(len(saved["answers"]), 23)

        with sqlite3.connect(self.database) as database:
            count = database.execute(
                "SELECT COUNT(*) FROM bervel_questionnaire_submissions"
            ).fetchone()[0]
        self.assertEqual(count, 1)

    def test_origin_and_confirmation_are_enforced(self) -> None:
        status, response = self.request("POST", payload(), origin="https://example.com")
        self.assertEqual(status, 403)
        self.assertEqual(response["error"]["code"], "origin_not_allowed")

        unconfirmed = payload()
        unconfirmed["answers"][0]["confirmed"] = False
        status, response = self.request("POST", unconfirmed, origin=ALLOWED_ORIGIN)
        self.assertEqual(status, 422)
        self.assertEqual(response["error"]["code"], "confirmation_required")
        self.assertEqual(response["error"]["details"]["questionId"], "C01")


if __name__ == "__main__":
    unittest.main()
