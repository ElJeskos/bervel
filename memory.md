# BERVEL questionnaire: VDS database integration

## Current status

As of 2026-09-02, the questionnaire submission flow is working end to end:

- questionnaire: <https://mox-studio.github.io/bervel/bervel-questionnaire.html>
- protected answers panel: <https://mox-studio.github.io/bervel/answers.html>
- DEV API on the VDS used by FvsB: <https://moxdev3.ru/ajax/bervel-questionnaire.php>
- the questionnaire and panel are published from `MOX-Studio/bervel` GitHub Pages;
- the API is published to the FvsB/EcoStandard DEV environment through the canonical Git/PR deployment flow;
- production `moxdev2.ru` and the EcoStandard database are not used by this integration.

The latest live browser test submission was saved successfully with ID
`046a42e1-6979-4fe5-96b3-4f60850f2ef7`. A read-only database check confirmed questionnaire
version `4.2`, all 25 answers, and a valid payload SHA-256. The same row was then displayed as
the newest version in the protected answers panel.

## Data flow

1. Every questionnaire textarea is required.
2. A prefilled answer is complete only when it still contains text and its confirmation is checked.
3. The **Отправить ответы** button remains disabled until progress is `25 из 25` / `100%`.
4. The browser sends JSON to the DEV API with schema `bervel-questionnaire/v1`.
5. The API validates the exact 25 question IDs, non-empty answers, required confirmations,
   questionnaire version, body size, UUID, and request origin.
6. The API writes one immutable submission row to SQLite. Repeating the same UUID and payload
   is idempotent; reusing a UUID with different data is rejected.
7. `answers.html` requests saved versions through an authenticated GET and renders every answer
   using text-only DOM APIs.

CORS is restricted to `https://mox-studio.github.io`. CORS limits browser origins but is not an
authentication mechanism; POST remains a public form endpoint. GET requires a Bearer access key.
The panel keeps that key only in `sessionStorage`, so it is removed when the tab/session is closed
or when **Закрыть доступ** is pressed.

## Server layout

SSH entry host used by the FvsB setup:

```text
moxdev.beget.tech
```

The BERVEL storage is isolated inside the DEV vhost and outside its public document root:

```text
/home/m/moxdev/moxdev3.beget.tech/.bervel-questionnaire/
├── config.php
└── data/
    └── answers.sqlite3
```

The initial `~/.bervel-questionnaire/` location did not work because web PHP is restricted by the
vhost `open_basedir`. The active location above is readable by DEV web PHP without exposing either
the configuration or database over HTTP.

Permissions established during setup:

- private directory: `0700`
- `config.php`: `0600`
- `answers.sqlite3`: `0600`

SQLite table:

```text
bervel_questionnaire_submissions
```

It stores the submission UUID, server timestamp, questionnaire version, answer count, normalized
answers JSON, payload SHA-256, and request origin. It does not store the admin key or raw IP address.
All writes must go through the API. Manual database diagnostics are SELECT-only.

At setup, the Beget filesystem reported 99% usage. The database is small, but this is an operational
risk: do not place screenshots, archives, or backups in this vhost, and check free space before any
larger server-side change.

## Source and deployment ownership

Canonical API source in this repository:

```text
server/bervel-questionnaire.php
```

Deployed copy in `MOX-Studio/eco-standard-bitrix`:

```text
ajax/bervel-questionnaire.php
```

The files must remain byte-identical. Backend changes follow this sequence:

1. update and test `server/bervel-questionnaire.php` here;
2. copy it to `ajax/bervel-questionnaire.php` in a fresh EcoStandard feature branch based on
   `origin/develop`;
3. run `php -l` and the BERVEL API integration tests;
4. open a PR into `develop` and let the documented supervisor merge it;
5. wait until `https://moxdev3.ru/zz_dev_state.json` reports the merged SHA;
6. verify the real DEV endpoint over HTTPS.

Creating a future EcoStandard branch still requires explicit permission for that task. Never push
directly to its `develop` or `main`, never upload API source through SSH, and never deploy this
integration to `moxdev2.ru` as a shortcut.

Initial deployment evidence:

- API PR: <https://github.com/MOX-Studio/eco-standard-bitrix/pull/333>
- vhost-path fix PR: <https://github.com/MOX-Studio/eco-standard-bitrix/pull/334>
- deployed DEV SHA: `f9d35bdd10e36fa3d387b342800e7bef09c9aa64`
- GitHub Pages implementation commit: `6ddb866f58130076ab5765bdd7356a8f16bd5363`

Frontend files are deployed from `deliverables/` by `.github/workflows/pages.yml` on pushes to this
repository's `main` branch.

## Admin credential

The plaintext admin key is not stored in Git, this file, server config, or task artifacts. The
server stores only its SHA-256 hash. The final key is stored in the current Mac user's Keychain:

```bash
security find-generic-password -w -s bervel-questionnaire-admin
```

Do not paste the key into a URL, commit, issue, PR, log, or screenshot. Enter it only in the protected
panel. To rotate it, generate a new random key, update the Keychain item, atomically replace only the
`admin_token_hash` value in the private DEV config, and verify that the old key returns HTTP 401 while
the new key returns HTTP 200.

## Safe verification

Use one multiplexed SSH session through the configured `moxdev.beget.tech` route. A bounded,
read-only database check looks like this:

```bash
sqlite3 "$HOME/moxdev3.beget.tech/.bervel-questionnaire/data/answers.sqlite3" \
  'SELECT id, created_at, questionnaire_version, answer_count FROM bervel_questionnaire_submissions ORDER BY created_at DESC LIMIT 5;'
```

Do not print `answers_json` during routine diagnostics because it contains customer answers.
For functional acceptance, use a fresh 1920×1080 Playwright session: verify the disabled state at
24/25, enabled state at 25/25, successful button state after POST, the row in the read-only query,
and the same 25 answers in `answers.html`.
