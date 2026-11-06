# Licence Register API — beginner's guide

This API lets another program (for example a **Microsoft Power Automate** flow) do what you would
otherwise do by hand on the dashboard:

1. **Upload** licence documents (`.pdf` / `.docx`), one at a time or many at once.
2. **Start processing** them.
3. **Check the status** until processing is finished.
4. **Download** the finished Excel workbook (`Master_Licences.xlsx`).
5. **Reset** (optional): wipe everything and start again from an empty register.

```
 ┌──────────────┐   ┌──────────────┐   ┌──────────────────────┐   ┌────────────────┐
 │ 1. Upload    │ → │ 2. Process   │ → │ 3. Status (repeat    │ → │ 4. Download    │
 │ POST         │   │ POST         │   │    until ready=true) │   │ GET            │
 │ /documents   │   │ /process     │   │ GET /status          │   │ /workbook      │
 └──────────────┘   └──────────────┘   └──────────────────────┘   └────────────────┘
```

The dashboard keeps working exactly as before; the API is an extra door into the same system.
Anything you do through the API (including a reset) also shows on the dashboard, and the other way
round.

---

## Contents

- [Before you start](#before-you-start)
- [The basics: address, API key, answers](#the-basics)
- [Endpoint 1: upload documents](#1-upload-documents)
- [Endpoint 2: start processing](#2-start-processing)
- [Endpoint 3: check the status](#3-check-the-status)
- [Endpoint 4: download the workbook](#4-download-the-workbook)
- [Endpoint 5: reset everything](#5-reset-everything)
- [Error codes](#error-codes)
- [Power Automate: step by step](#power-automate-step-by-step)
- [Try it from the command line](#try-it-from-the-command-line)
- [FAQ](#faq)

---

## Before you start

You need three things from whoever runs the server:

| What | Example | Notes |
|---|---|---|
| **Base URL** | `https://licences.example.com` | Must be reachable from the internet over **HTTPS** if you use Power Automate (it runs in Microsoft's cloud and cannot reach `localhost` or a private network). |
| **API key** | `3f9c1a…e7b2` (64 characters) | A secret password for programs. Treat it like a password. |
| A working **Gemini key** on the server | — | The administrator sets this up; without it, step 2 fails with `extraction_not_configured`. |

### For the administrator: turning the API on

The API is **off** until at least one key is configured.

1. Generate a key (run in the project folder):

   ```bash
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```

2. Put it in the `.env` file. Use a different key for each flow or team, separated by commas, so
   one can be removed without breaking the others:

   ```dotenv
   API_KEYS=3f9c1a...e7b2,8d02b4...91aa
   ```

3. Make sure documents actually get processed after step 2. With the default settings the server
   starts a background run automatically. On shared hosting (cPanel / LiteSpeed) where the
   security layer blocks PHP from starting processes — `POST /process` then fails with an HTML
   `403 Forbidden` page — set `LICENCE_QUEUE_DISPATCH=inline`: the request returns `202` at once and
   the same PHP process works through the queue afterwards. If you set `LICENCE_QUEUE_DISPATCH=none`, keep the worker
   running (`php runway licences:work`), otherwise documents wait for the next scheduled run.

To revoke a key, remove it from `API_KEYS`. The change takes effect on the next request.

---

## The basics

### Address

Every endpoint starts with:

```
<Base URL>/api/v1
```

For example: `https://licences.example.com/api/v1/status`.

### API key

Send your key on **every** request in this header:

```
X-API-Key: <your key>
```

(`Authorization: Bearer <your key>` also works, if a tool prefers that.)

Never put the key in the URL. Without a valid key every endpoint answers **401 Unauthorized**.

### Answers

- Every answer is **JSON**, except a successful workbook download, which is the Excel file itself.
- When something goes wrong the answer always looks like this:

  ```json
  {
    "error": "still_processing",
    "message": "2 document(s) are still being processed. Try again in about 30 seconds."
  }
  ```

  `error` is a fixed code your flow can check; `message` is a sentence for people.

- The HTTP status code tells you the outcome at a glance: **2xx = OK**, **4xx = something about the
  request** (or "not ready yet"), **5xx = a server problem**.

---

## 1. Upload documents

```
POST /api/v1/documents
```

Puts one or more documents into the inbox. **It does not process them yet**; that is step 2.

Rules for each file:

- Only `.pdf` and `.docx` (old `.doc` files are not accepted; save them as `.docx` first).
- At most **20 MB** per file (the administrator can change this).
- The file content must really be a PDF / Word file; a renamed file is rejected.
- If a file with the same name is already waiting, the new one is saved as `name (2).pdf`.

You can send files in **either** of two ways.

### Option A: JSON with base64 (easiest in Power Automate)

"Base64" is a way to write a file as plain text. Power Automate does it for you with the
`base64(...)` expression, and Outlook attachments already come as base64 (`contentBytes`).

Header: `Content-Type: application/json`

One file:

```json
{
  "name": "0881-26-R.pdf",
  "content": "JVBERi0xLjcKJc..."
}
```

Several files:

```json
{
  "files": [
    { "name": "0881-26-R.pdf", "content": "JVBERi0xLjcKJc..." },
    { "name": "0470-26-R.docx", "content": "UEsDBBQABgAIAAAAIQ..." }
  ]
}
```

Also accepted, so you can pass data from other connectors without reshaping it:

| Instead of `"content": "<base64>"` you may send | Typical source |
|---|---|
| `"contentBytes": "<base64>"` | Outlook attachments |
| `"content": { "$content-type": "application/pdf", "$content": "<base64>" }` | SharePoint *Get file content* |
| A plain list `[ {...}, {...} ]` instead of `{"files": [...]}` | — |

### Option B: multipart/form-data (normal file upload)

Send the files as form fields. Any field name works; use `files` for clarity:

```bash
curl -H "X-API-Key: $KEY" \
     -F "files=@0881-26-R.pdf" -F "files=@0470-26-R.docx" \
     https://licences.example.com/api/v1/documents
```

### Answer

**201 Created**: at least one file was saved.

```json
{
  "uploaded": ["0881-26-R.pdf", "0470-26-R.docx"],
  "errors": [],
  "message": "2 document(s) uploaded. Call POST /api/v1/process to start processing."
}
```

`uploaded` lists the names the files were saved under. If **some** files were rejected, they are
listed in `errors` with the reason, and the good files are still saved:

```json
{
  "uploaded": ["0881-26-R.pdf"],
  "errors": ["image001.png: only .docx and .pdf files are accepted."],
  "message": "1 document(s) uploaded, 1 rejected. Call POST /api/v1/process to start processing."
}
```

| Status | `error` | Meaning |
|---|---|---|
| 400 | `no_files` | The request contained no files at all. |
| 422 | `invalid_files` | Files were sent but **none** could be saved; see `errors`. |

---

## 2. Start processing

```
POST /api/v1/process
```

No body needed. Starts extracting **every** document that is waiting and returns straight away;
the work continues in the background (about 3 seconds per document, longer for big scans).

### Answer

**202 Accepted**: processing has started.

```json
{
  "status": "processing",
  "queued": 2,
  "documents_in_progress": 2,
  "message": "Processing started. Poll GET /api/v1/status until \"ready\" is true, then download GET /api/v1/workbook.",
  "counts": { "uploaded": 0, "queued": 2, "processing": 0, "failed": 0 }
}
```

**200 OK** with `"status": "idle"`: there was nothing to process.

Calling this twice is harmless: documents that are already queued are not queued again.

| Status | `error` | Meaning |
|---|---|---|
| 503 | `extraction_not_configured` | The server has no Gemini key. Ask the administrator. |
| 500 | `processor_not_started` | Documents are queued but the server could not start working on them. Ask the administrator. |

---

## 3. Check the status

```
GET /api/v1/status
```

Tells you what is still waiting and whether the workbook is ready. Call it every 30 seconds or so
until `ready` is `true`.

### Answer

**200 OK**

```json
{
  "state": "processing",
  "ready": false,
  "counts": { "uploaded": 0, "queued": 1, "processing": 1, "failed": 0 },
  "documents": [
    { "name": "0881-26-R.pdf",  "state": "processing", "attempts": 1, "error": null, "uploaded_at": "2026-11-04T09:15:02+05:30" },
    { "name": "0470-26-R.docx", "state": "queued",     "attempts": 0, "error": null, "uploaded_at": "2026-11-04T09:15:02+05:30" }
  ],
  "workbook": { "exists": true, "updated_at": "2026-11-03T17:40:11+05:30", "size_bytes": 18234 }
}
```

| Field | Meaning |
|---|---|
| `ready` | **The one to check.** `true` = nothing is waiting and the workbook exists, so you can download it. |
| `state` | `processing` (work in progress), `waiting_to_start` (uploaded, but step 2 not called yet), or `idle` (nothing waiting). |
| `counts` | How many documents are in each state. |
| `documents` | Only documents still in the inbox. Finished documents disappear from this list. |
| `documents[].state` | `uploaded` → `queued` → `processing` → (done, removed from the list), or `failed`. |
| `documents[].error` | Why the last attempt failed, if it did. |
| `workbook` | Whether the Excel file exists and when it last changed. |

A document that fails is retried automatically (up to 3 attempts). If it still fails it becomes
`failed` and stays there; someone must fix it and press **Retry** on the dashboard. **Failed
documents do not block the download**, so your flow will not get stuck on one bad file.

---

## 4. Download the workbook

```
GET /api/v1/workbook
```

### Answer when ready

**200 OK**: the body is the Excel file (`Master_Licences.xlsx`), not JSON.

Useful response headers:

| Header | Meaning |
|---|---|
| `Content-Type` | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` (Excel) |
| `Content-Disposition` | `attachment; filename="Master_Licences.xlsx"` |
| `X-Failed-Documents` | How many documents failed and are **not** in the workbook (usually `0`). |

The workbook is the full register: every licence ever processed, not only today's.

### Answer when not ready

| Status | `error` | Meaning | What to do |
|---|---|---|---|
| 409 | `still_processing` | Documents are queued or being processed. A `Retry-After: 30` header says how long to wait. | Wait and try again (or poll `/status`). |
| 409 | `processing_not_started` | Documents were uploaded but step 2 was never called. | Call `POST /api/v1/process`. |
| 404 | `workbook_not_found` | Nothing has ever been processed, so there is no workbook. | Upload and process documents first. |

---

## 5. Reset everything

```
POST /api/v1/reset
```

> ⚠️ **This permanently deletes data and cannot be undone.** Download the workbook first if you
> want to keep it.

Clears the whole app so it is like a fresh install:

| Deleted | |
|---|---|
| Documents waiting to be processed | including failed ones |
| Documents already processed | the archived originals |
| The Excel workbook | `Master_Licences.xlsx`, so the register starts empty |
| The job queue | every job, whatever its state |
| The activity log | the **Activity** list on the dashboard |

Settings, API keys and the Gemini key are **not** touched.

To make sure nobody resets by accident, the request **must** contain this body
(header `Content-Type: application/json`):

```json
{ "confirm": "RESET" }
```

`RESET` must be in capital letters exactly like that.

### Answer

**200 OK**

```json
{
  "status": "reset",
  "deleted": {
    "jobs": 5,
    "documents_waiting": 1,
    "documents_processed": 4,
    "workbook": true,
    "log": true
  },
  "message": "Everything has been cleared. The register is empty and ready for new documents."
}
```

`deleted` shows what was removed (`workbook` / `log` are `false` if there was none).

| Status | `error` | Meaning | What to do |
|---|---|---|---|
| 400 | `confirmation_required` | The body did not contain `{"confirm": "RESET"}`. Nothing was deleted. | Add the body. |
| 409 | `reset_blocked` | A document is being extracted right now. Nothing was deleted. A `Retry-After: 30` header is included. | Wait half a minute and try again. |
| 500 | `reset_failed` | A file could not be deleted (on Windows, usually the workbook is open in Excel). Part of the data may already be gone. | Close the file and call reset again; it is safe to repeat. |

Documents that are only *queued* (not yet being extracted) do not block a reset; they are deleted.

---

## Error codes

| HTTP | `error` | Where | What it means |
|---|---|---|---|
| 401 | `unauthorized` | any | Missing or wrong API key. Check the `X-API-Key` header. |
| 503 | `api_disabled` | any | The server has no API keys configured; the API is switched off. |
| 400 | `no_files` | upload | No files in the request. |
| 422 | `invalid_files` | upload | Every file was rejected; see `errors`. |
| 503 | `extraction_not_configured` | process | No Gemini key on the server. |
| 500 | `processor_not_started` | process | Background processing could not be started. |
| 409 | `processing_not_started` | workbook | Uploaded documents are waiting for `POST /process`. |
| 409 | `still_processing` | workbook | Not finished yet; try again later. |
| 404 | `workbook_not_found` | workbook | No workbook exists yet. |
| 400 | `confirmation_required` | reset | Body `{"confirm": "RESET"}` missing; nothing deleted. |
| 409 | `reset_blocked` | reset | A document is being processed; try again shortly. |
| 500 | `reset_failed` | reset | A file could not be deleted (open in another program?). |

---

## Power Automate: step by step

> **Licence note:** the **HTTP** action used below is a *Premium* connector in Power Automate.
> Your account needs a Premium (or per-flow) licence to use it.

The example flow: **when an email with licence documents arrives → send them to the API → wait →
save the updated workbook to SharePoint and email it.** Adapt the first and last steps to your own
process; the middle part (steps 2–5) is always the same.

### Tip: keep the key in one place

Add an **Initialize variable** action at the top of the flow:

- Name: `ApiKey`, Type: **String**, Value: your API key

Then use the variable in every HTTP action. On each HTTP action open **⋯ → Settings** and turn on
**Secure inputs** and **Secure outputs** so the key is not shown in the run history.

Also add a variable `BaseUrl` (String) with a value like `https://licences.example.com/api/v1`.

### Step 1: trigger

**When a new email arrives (V3)** (Office 365 Outlook):

- *Include Attachments*: **Yes**
- *Only with Attachments*: **Yes**
- Optionally filter by subject or sender.

### Step 2: upload the attachments

1. Add a **Select** action (Data Operation):
   - *From*: `Attachments` (dynamic content from the trigger)
   - *Map*: switch to text mode (the **T** icon) and enter:

     ```json
     {
       "name": "@{item()?['name']}",
       "content": "@{item()?['contentBytes']}"
     }
     ```

2. Add an **HTTP** action, rename it **Upload documents**:

   | Field | Value |
   |---|---|
   | Method | `POST` |
   | URI | `@{variables('BaseUrl')}/documents` |
   | Headers | `X-API-Key` → `@{variables('ApiKey')}` <br> `Content-Type` → `application/json` |
   | Body | `{ "files": @{body('Select')} }` |

Emails often contain logos or signature images; those are simply rejected (listed in `errors`) and
the PDFs / Word files are still uploaded. If an email contains *only* images the API answers 422 and
the action fails. To avoid that, add a **Filter array** before **Select** keeping attachments whose
name ends with `.pdf` or `.docx`, and skip the rest of the flow when nothing is left.

**Files from SharePoint instead of email?** Use *Get file content* and, in the HTTP body:

```json
{
  "name": "@{triggerOutputs()?['body/{FilenameWithExtension}']}",
  "content": "@{base64(body('Get_file_content'))}"
}
```

### Step 3: start processing

Add an **HTTP** action, rename it **Start processing**:

| Field | Value |
|---|---|
| Method | `POST` |
| URI | `@{variables('BaseUrl')}/process` |
| Headers | `X-API-Key` → `@{variables('ApiKey')}` |
| Body | *(leave empty)* |

### Step 4: wait until it is ready

1. Add a **Do until** action.
2. Inside it, add:
   1. **Delay**: Count `30`, Unit `Second`.
   2. **HTTP** action, renamed **Check status**:

      | Field | Value |
      |---|---|
      | Method | `GET` |
      | URI | `@{variables('BaseUrl')}/status` |
      | Headers | `X-API-Key` → `@{variables('ApiKey')}` |

3. Set the **Do until** condition (switch to *Edit in advanced mode*):

   ```
   @equals(body('Check_status')?['ready'], true)
   ```

4. Expand **Change limits** on the Do until: *Count* `60`, *Timeout* `PT1H`. That allows up to an
   hour for big batches; after that the loop stops instead of running forever.

### Step 5: download the workbook

Add an **HTTP** action after the loop (not inside it), rename it **Download workbook**:

| Field | Value |
|---|---|
| Method | `GET` |
| URI | `@{variables('BaseUrl')}/workbook` |
| Headers | `X-API-Key` → `@{variables('ApiKey')}` |

The body of this action **is the Excel file**. Use it directly as file content:

- **SharePoint → Create file**
  - *File Name*: `Master_Licences.xlsx` (or `Master_Licences_@{formatDateTime(utcNow(),'yyyy-MM-dd')}.xlsx`)
  - *File Content*: `Body` of **Download workbook**
- **Office 365 Outlook → Send an email (V2)**, *Attachments*:
  - *Name*: `Master_Licences.xlsx`
  - *Content*: `Body` of **Download workbook**

To warn someone about failed documents, add a **Condition** after the download:

```
@greater(int(outputs('Download_workbook')?['headers']?['X-Failed-Documents']), 0)
```

and send a notification in the *If yes* branch. Details of each failure are in the last
**Check status** result (`documents[].error`) and on the dashboard.

### Optional: reset before or after a run

If every run should start from an empty register (so the workbook contains **only** this run's
documents), add an **HTTP** action **before step 2**, renamed **Reset**:

| Field | Value |
|---|---|
| Method | `POST` |
| URI | `@{variables('BaseUrl')}/reset` |
| Headers | `X-API-Key` → `@{variables('ApiKey')}` <br> `Content-Type` → `application/json` |
| Body | `{ "confirm": "RESET" }` |

Only do this if nobody else (another flow or someone on the dashboard) relies on the register,
because a reset clears it for everyone.

### Common Power Automate problems

| Symptom | Fix |
|---|---|
| 401 `unauthorized` | The header name must be exactly `X-API-Key`; check the variable has no spaces or line breaks. |
| `The request failed... could not be reached` | The server is not reachable from the internet, or not on HTTPS. |
| 422 `invalid_files` | Only images / unsupported files were sent. Filter attachments (see step 2). |
| Do until stops after an hour with `ready` still false | Something is stuck; open the dashboard and look at the **Activity** log. |
| 409 `processing_not_started` | The **Start processing** step was skipped or failed. |
| 400 `confirmation_required` on reset | Body must be exactly `{ "confirm": "RESET" }` and the `Content-Type: application/json` header must be set. |
| Downloaded file will not open | Make sure you use the action's `Body` as file content, not a `string(...)` of it. |

---

## Try it from the command line

Handy to check the setup before building a flow. `curl` is built into Windows 10+, macOS and Linux.
(On Windows PowerShell, type `curl.exe` instead of `curl`, and replace the `\` line breaks with
`` ` ``.)

```bash
KEY="your-api-key"
URL="https://licences.example.com/api/v1"

# Is my key OK? (expects 200 and a JSON status)
curl -H "X-API-Key: $KEY" "$URL/status"

# 1. Upload two files
curl -H "X-API-Key: $KEY" -F "files=@licence1.pdf" -F "files=@licence2.docx" "$URL/documents"

# 2. Start processing
curl -X POST -H "X-API-Key: $KEY" "$URL/process"

# 3. Check progress (repeat until "ready": true)
curl -H "X-API-Key: $KEY" "$URL/status"

# 4. Download the workbook
curl -H "X-API-Key: $KEY" -o Master_Licences.xlsx "$URL/workbook"

# 5. (Optional) wipe everything and start over. Cannot be undone!
curl -X POST -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
     -d '{"confirm": "RESET"}' "$URL/reset"
```

(PowerShell: `-d '{\"confirm\": \"RESET\"}'`.)

---

## FAQ

**Do I have to call `/process`, or does it happen by itself?**
Always call it. Documents uploaded through the API wait for it. (If the server also runs a
scheduled job or a worker, those may pick documents up sooner; calling `/process` is still safe.)

**Can several flows use the API at the same time?**
Yes. They share one inbox and one workbook, so `/status` and `/workbook` reflect everyone's
documents, and the download waits until *all* waiting documents are done.

**Will uploading the same licence twice create duplicate rows?**
No. Rows are matched on *VLFileNumber + VLNumber*; a repeat is skipped.

**Does the workbook only contain the documents I just sent?**
No, it is the whole register. Filter it in Excel or in your flow if you need only new rows, or
call `POST /api/v1/reset` at the start of each run so the register only ever holds that run's
documents.

**I reset by mistake. Can I get the data back?**
No. The API does not keep a backup. Re-upload and re-process the original documents to rebuild the
register.

**How big can a batch be?**
There is no limit on the number of files, but each file must be under 20 MB and the whole request
must fit the server's upload limit. Base64 makes files about one third larger, so for very large
batches upload in several requests and call `/process` once at the end.

**Is my data safe?**
The API only works with a valid key, over HTTPS, and accepts only real PDF / Word files. Keep the
key secret, use **Secure inputs** in Power Automate, and ask the administrator to remove a key from
`API_KEYS` if it may have leaked.
