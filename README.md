# Licence Register

Turns Sri Lankan vendor licence documents (`.docx` / `.pdf`, including scans) into one clean Excel
register. Each document is read by the Gemini API, the business rules are applied in PHP, and every
licence becomes a row in `Master_Licences.xlsx`, with no copy-typing.

| Column | Example | Rule |
|---|---|---|
| VLFileNumber | `LCS/VEN/4315/2024` | Trimmed, no spaces around slashes |
| VLNumber | `0881/26/R` | Leading/trailing noise removed (`..0470/26/R` → `0470/26/R`) |
| CompanyName | `ABC MOBILE (PVT) LTD` | Legal-entity brackets kept, wrapping brackets removed |
| Address1 | `NO. 178, …, MATARA.`⏎`NO.48, …, BELIATTA.` | One premises per line, each ends with a period, ASCII `NO.` (never `№`) |
| ExpireDate | `29/09/2027` | Effective date + 1 year − 1 day, a real date shown as DD/MM/YYYY, right-aligned |
| LicenceType | `WD,WL excluding SAT,GPSVT` | WD = wired, WL = wireless; exclusions CP, SAT, GPSVT, RCT |
| District | `HAMBANTOTA, MATARA` | Sri Lankan district per premises, upper case, de-duplicated, sorted |

Rows are de-duplicated on **VLFileNumber + VLNumber**, so processing the same document twice never
adds it twice.

## Features

- **Web dashboard**: drop documents in, watch them get extracted, filter the register, see which
  licences have expired or expire within 90 days, and download the workbook.
- **Job queue**: every document is a job with automatic retries (back-off for Gemini rate limits),
  a live status in the dashboard, and a **Retry** button for documents that failed.
- **REST API**: upload documents, start processing, poll the status and download the workbook from
  another system such as Power Automate, protected by API keys. See **[docs/API.md](docs/API.md)**.
- **Unattended mode**: a daily scheduled run or an always-on worker processes anything placed in the
  incoming folder.
- **Accurate by design**: Gemini only reads facts off the page, using a strict JSON schema; all
  formatting rules are applied in PHP (`App\Service\LicenceNormalizer`), so output is consistent.

## Requirements

- PHP **8.4+** with `curl`, `zip`, `dom` and `pdo_sqlite` (or `pdo_mysql`)
- Composer
- A Gemini API key: free from [Google AI Studio](https://aistudio.google.com)
- Node.js only if you change the UI styles

## Getting started

```bash
git clone https://github.com/TharinduWijayarathna/license-data-extractor.git
cd license-data-extractor
composer install
cp app/config/config_sample.php app/config/config.php
cp .env.example .env            # then set GEMINI_API_KEY=...
php runway migrate              # creates the job queue table (SQLite by default)
composer start                  # → http://localhost:8000
```

Upload a document on the dashboard, and the row appears in the register a few seconds later.

## How processing works

```
upload / API / folder ──► incoming/ ──► job queue ──► processor ──► Gemini ──► rules ──► Master_Licences.xlsx
                                  (database)    (one at a time)                     document → processed/
```

1. **Documents arrive** in `storage/licences/incoming/`: uploaded on the dashboard, sent to the
   API, or copied into the folder directly.
2. **Each document becomes a job** in the `licence_jobs` table: *queued → processing → done*.
3. **A processor works through the queue**, one document at a time (about 3 seconds each). Only one
   processor runs at once; a lock file guarantees it, so the scheduler, the worker and the dashboard
   never clash and never exceed Gemini's rate limits.
4. **Success**: the new rows are appended to the workbook (written atomically) and the document
   moves to `storage/licences/processed/`.
5. **Failure**: the job is retried automatically (after 60 s, then 120 s, …, up to 3 attempts).
   If it still fails, it is marked *failed* with the reason, the document stays in `incoming/`, and
   you can press **Retry** on the dashboard once the problem is fixed. Every outcome is logged to
   `app/log/licence_agent.log` and shown under **Activity**.

**What starts a processor?**

| Trigger | When to use |
|---|---|
| Dashboard upload or **Extract** | Starts a background run automatically, and the page updates itself as jobs finish. |
| `POST /api/v1/process` | Same as **Extract**, for automations (API uploads wait for this call). |
| `php runway licences:process` | Processes everything queued (waiting for short retries), then exits. Ideal for a daily schedule. |
| `php runway licences:work` | Stays running and picks documents up within seconds. Use on a server under a process manager. |

If the database is not configured or not migrated, the app still works: it falls back to
processing the incoming folder synchronously, as a single batch.

## Running it unattended

### Option A: daily schedule

```bash
# macOS / Linux (crontab -e): every day at 06:00
0 6 * * * cd /path/to/license-data-extractor && php runway licences:process >/dev/null 2>&1
```

On Windows, create a **Task Scheduler** task that runs `php.exe runway licences:process`, with
*Start in* set to the project folder and *Run whether user is logged on or not* checked.

### Option B: always-on worker

```ini
# /etc/systemd/system/licence-worker.service
[Unit]
Description=Licence Register queue worker

[Service]
WorkingDirectory=/path/to/license-data-extractor
ExecStart=/usr/bin/php runway licences:work --sleep=5
Restart=always

[Install]
WantedBy=multi-user.target
```

The worker stops cleanly on `SIGTERM` / Ctrl+C after the current document. Use `--max-runtime=3600`
to have it exit hourly so the process manager restarts it fresh. On Windows, run the same command as
a service with [NSSM](https://nssm.cc). With a worker running you can set
`LICENCE_QUEUE_DISPATCH=none`, so uploads just queue and leave the work to it.

## REST API

For automations (Power Automate, scripts, other systems). Every request needs an API key in the
`X-API-Key` header; the API is disabled until `API_KEYS` is set in `.env`.

| Endpoint | What it does |
|---|---|
| `POST /api/v1/documents` | Upload one or many `.pdf` / `.docx` files (multipart or JSON base64) |
| `POST /api/v1/process` | Start processing everything uploaded; returns at once (202) |
| `GET /api/v1/status` | Per-document state and `ready: true` once the workbook can be downloaded |
| `GET /api/v1/workbook` | The `.xlsx`, or `409` with an explanation while documents are still processing |
| `POST /api/v1/reset` | Delete all documents, the workbook, the queue and the log (body `{"confirm": "RESET"}`) |

**[docs/API.md](docs/API.md)** explains every endpoint, error and a complete Power Automate flow,
written for people who have never used an API.

## Configuration

Set these in `.env`; defaults live in `app/config/config.php` (`gemini.*`, `licences.*`).

| Variable | Default | Purpose |
|---|---|---|
| `GEMINI_API_KEY` | (none) | **Required** to extract documents |
| `API_KEYS` | (none) | Comma-separated keys for the REST API; empty disables it. Generate: `php -r "echo bin2hex(random_bytes(32));"` |
| `GEMINI_MODEL` | `gemini-3.8-flash` | Pinned model; `thinking_level` is `low` (fast and accurate for extraction) |
| `LICENCE_INCOMING_DIR` | `storage/licences/incoming` | Documents waiting to be processed |
| `LICENCE_PROCESSED_DIR` | `storage/licences/processed` | Documents already processed |
| `LICENCE_MASTER_XLSX` | `storage/licences/Master_Licences.xlsx` | The register workbook |
| `LICENCE_LOG_FILE` | `app/log/licence_agent.log` | Activity / error log |
| `LICENCE_QUEUE_DISPATCH` | `background` | `background`: uploads start a detached run (`exec`); `inline`: process in the web request after the response is sent (shared hosting / cPanel, where `exec` is blocked); `none`: leave jobs to the worker / schedule |
| `LICENCE_PHP_BINARY` | auto | CLI `php` used for background runs, if auto-detection picks the wrong one |
| `DB_DRIVER` / `DB_SQLITE_PATH` | `sqlite` / `database.sqlite` | Queue database (`mysql` supported, see below) |
| `DB_HOST` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | (none) | MySQL connection, when `DB_DRIVER=mysql` |
| `APP_TIMEZONE` | `UTC` | e.g. `Asia/Colombo`, used for log times and expiry checks |
| `APP_DEBUG` | `true` | Set `false` in production (hides the debug bar and error details) |

Retry policy (`licences.queue.max_attempts`, `retry_after`, `stale_after`) and the upload size limit
(`licences.max_upload_mb`, 20 MB) are in `config.php`.

## Commands

| Command | What it does |
|---|---|
| `composer start` | Local web server on http://localhost:8000 (20 MB uploads allowed) |
| `php runway migrate` | Create / update the database tables |
| `php runway licences:process` | Process the queue once, then exit |
| `php runway licences:work` | Run the queue worker continuously (`--sleep`, `--max-runtime`) |
| `composer check` | Run the tests (PHPUnit) and static analysis (PHPStan level 8) |
| `npm run build` | Rebuild the UI stylesheet and fonts after changing views |

## Project structure

```
app/
  commands/      licences:process, licences:work (Runway CLI)
  config/        config, routes, services
  Controller/    LicenceController: dashboard, upload, extract, retry, status feed
                 ApiController: REST API (/api/v1)
  Middleware/    CSRF protection, API key check, security headers
  Service/       the pipeline:
                   GeminiLicenceExtractor  document → raw facts (Gemini, JSON schema)
                   DocxTextExtractor       .docx → text (Gemini cannot read Word files)
                   LicenceNormalizer       business rules → LicenceRecord
                   MasterWorkbook          Excel register (PhpSpreadsheet)
                   LicenceAgent            processes one document / a batch, locking
                   LicenceQueue            job queue (licence_jobs table)
                   LicenceWorker           runs queued jobs through the agent
                   LicenceDispatcher       starts background runs from the web
                   LicenceInbox            validated uploads, incoming folder listing
                   LicenceRegister         shared by dashboard + API: document states, starting runs
  views/         Twig templates
docs/            API.md: REST API and Power Automate guide
migrations/      SQL migrations (SQLite + MySQL pairs)
public/          web root: index.php, built CSS, fonts, JS
resources/css/   Tailwind source
storage/licences incoming/, processed/, Master_Licences.xlsx (not committed)
tests/           PHPUnit tests
```

Built with [Flight](https://docs.flightphp.com) (PHP micro-framework), Twig, PhpSpreadsheet,
Tailwind CSS and the Gemini API.

## Development

```bash
composer check        # tests + static analysis; run after any PHP / Twig change
npm install           # once, for UI work
npm run build         # copy fonts + build public/assets/app.css
npm run dev:css       # rebuild the CSS on save
```

`public/assets/app.css` and the fonts are committed, so servers do not need Node.

## Security

- The dashboard has **no login**. Run it on a trusted network or put authentication in front of it
  before exposing it to the internet.
- The REST API requires a key from `API_KEYS` (compared in constant time) and is off when none is
  set. Serve it over HTTPS only, and give each integration its own key so it can be revoked alone.
- All forms are CSRF-protected. Uploads are checked for extension, size and file signature, and
  stored outside the web root.
- Keep `GEMINI_API_KEY` and `API_KEYS` in `.env` only; `.env`, `config.php`, the workbook, documents and logs are
  git-ignored. Set `APP_DEBUG=false` in production.
