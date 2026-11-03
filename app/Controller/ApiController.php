<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\LicenceQueue;
use App\Service\LicenceRegister;
use flight\Engine;
use flight\net\UploadedFile;

/**
 * REST API (/api/v1) for automations such as Power Automate:
 * upload documents → start processing → poll status → download the workbook.
 *
 * Every route sits behind ApiKeyMiddleware. Errors are JSON:
 * {"error": "<code>", "message": "<human readable>"}.
 */
class ApiController
{
    /** Seconds a client should wait before asking again while documents are processing. */
    private const RETRY_AFTER = 30;

    /** @var Engine */
    private $app;

    /** @var LicenceRegister */
    private $register;

    /**
     * @param Engine $app
     */
    public function __construct(Engine $app, LicenceRegister $register)
    {
        $this->app = $app;
        $this->register = $register;
    }

    /**
     * POST /api/v1/documents: one or many .docx / .pdf files, either as
     * multipart/form-data (any field name) or as JSON with base64 contents.
     */
    public function upload(): void
    {
        $inbox = $this->register->inbox();
        $uploaded = [];
        $errors = [];
        $received = 0;

        foreach ($this->multipartFiles() as $file) {
            $received++;
            try {
                $uploaded[] = $inbox->store(
                    $file->getClientFilename(),
                    $file->getTempName(),
                    $file->getError(),
                    $file->getSize()
                );
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        foreach ($this->jsonFiles() as $index => $file) {
            $received++;
            $name = $file['name'];
            if ($name === '') {
                $errors[] = "File #{$index}: \"name\" is required (e.g. \"licence.pdf\").";
                continue;
            }
            if ($file['contents'] === null) {
                $errors[] = "{$name}: \"content\" must be the file encoded as base64.";
                continue;
            }
            try {
                $uploaded[] = $inbox->storeContents($name, $file['contents']);
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($received === 0) {
            $this->error(400, 'no_files', 'No files received. Send multipart/form-data files, or JSON {"files": [{"name": "licence.pdf", "content": "<base64>"}]}.');
            return;
        }
        if ($uploaded === []) {
            $this->app->json([
                'error' => 'invalid_files',
                'message' => 'None of the files could be uploaded.',
                'errors' => $errors,
            ], 422);
            return;
        }

        $this->app->json([
            'uploaded' => $uploaded,
            'errors' => $errors,
            'message' => sprintf(
                '%d document(s) uploaded%s. Call POST /api/v1/process to start processing.',
                count($uploaded),
                $errors === [] ? '' : ', ' . count($errors) . ' rejected'
            ),
        ], 201);
    }

    /**
     * POST /api/v1/process: start extracting every uploaded document.
     * Returns at once; processing continues in the background.
     */
    public function process(): void
    {
        if (!$this->register->hasGeminiKey()) {
            $this->error(503, 'extraction_not_configured', 'Document extraction is not configured on the server (GEMINI_API_KEY is missing).');
            return;
        }

        $queued = $this->register->queueWaiting();
        $counts = LicenceRegister::countStates($this->register->documents());
        $waiting = $counts[LicenceRegister::UPLOADED] + $counts[LicenceQueue::QUEUED] + $counts[LicenceQueue::PROCESSING];

        if ($waiting === 0) {
            $this->app->json([
                'status' => 'idle',
                'message' => 'Nothing to process. Upload documents first with POST /api/v1/documents.',
                'counts' => $counts,
            ]);
            return;
        }

        $run = $this->register->startProcessor();
        if ($run === LicenceRegister::RUN_FAILED) {
            $this->error(500, 'processor_not_started', 'The documents are queued but a background run could not be started. Ask the administrator to check the server.');
            return;
        }

        $this->app->json([
            'status' => 'processing',
            'queued' => $queued,
            'documents_in_progress' => $waiting,
            'message' => $run === LicenceRegister::RUN_DEFERRED
                ? 'Documents queued. The server worker or scheduled run will process them.'
                : 'Processing started. Poll GET /api/v1/status until "ready" is true, then download GET /api/v1/workbook.',
            'counts' => $counts,
        ], 202);
    }

    /**
     * GET /api/v1/status: what is still waiting and whether the workbook is ready.
     */
    public function status(): void
    {
        $documents = $this->register->documents();
        $counts = LicenceRegister::countStates($documents);
        $state = $this->overallState($counts);
        $master = $this->register->masterPath();
        $exists = is_file($master);

        $this->app->json([
            'state' => $state,
            'ready' => $state === 'idle' && $exists,
            'counts' => $counts,
            'documents' => array_map(function (array $document): array {
                return [
                    'name' => $document['name'],
                    'state' => $document['state'],
                    'attempts' => $document['job']['attempts'] ?? 0,
                    'error' => $document['job']['error'] ?? null,
                    'uploaded_at' => date('c', $document['modified']),
                ];
            }, $documents),
            'workbook' => [
                'exists' => $exists,
                'updated_at' => $exists ? date('c', (int) filemtime($master)) : null,
                'size_bytes' => $exists ? (int) filesize($master) : 0,
            ],
        ]);
    }

    /**
     * GET /api/v1/workbook: the master .xlsx, only once nothing is waiting.
     */
    public function download(): void
    {
        $counts = LicenceRegister::countStates($this->register->documents());
        $state = $this->overallState($counts);

        if ($state === 'processing') {
            $busy = $counts[LicenceQueue::QUEUED] + $counts[LicenceQueue::PROCESSING] + $counts[LicenceRegister::UPLOADED];
            $this->app->response()->header('Retry-After', (string) self::RETRY_AFTER);
            $this->app->json([
                'error' => 'still_processing',
                'message' => "{$busy} document(s) are still being processed. Try again in about " . self::RETRY_AFTER . ' seconds.',
                'counts' => $counts,
            ], 409);
            return;
        }
        if ($state === 'waiting_to_start') {
            $this->app->json([
                'error' => 'processing_not_started',
                'message' => $counts[LicenceRegister::UPLOADED] . ' uploaded document(s) have not been processed yet. Call POST /api/v1/process first.',
                'counts' => $counts,
            ], 409);
            return;
        }

        $path = $this->register->masterPath();
        if (!is_file($path)) {
            $this->error(404, 'workbook_not_found', 'There is no workbook yet. Upload documents and process them first.');
            return;
        }

        $this->app->response()
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Disposition', 'attachment; filename="' . basename($path) . '"')
            ->header('X-Failed-Documents', (string) $counts[LicenceQueue::FAILED])
            ->write((string) file_get_contents($path));
    }

    /**
     * POST /api/v1/reset: delete all documents, the workbook, the job queue and
     * the activity log. Requires {"confirm": "RESET"} so it is never accidental.
     */
    public function reset(): void
    {
        if (($this->app->request()->data['confirm'] ?? null) !== 'RESET') {
            $this->error(400, 'confirmation_required', 'Reset deletes all documents, the workbook, the job queue and the activity log. To confirm, send {"confirm": "RESET"}.');
            return;
        }

        $processing = LicenceRegister::countStates($this->register->documents())[LicenceQueue::PROCESSING];
        if ($processing > 0) {
            $this->app->response()->header('Retry-After', (string) self::RETRY_AFTER);
            $this->error(409, 'reset_blocked', "{$processing} document(s) are being processed right now. Try again in about " . self::RETRY_AFTER . ' seconds.');
            return;
        }

        try {
            $deleted = $this->register->reset();
        } catch (\RuntimeException $e) {
            $this->error(500, 'reset_failed', 'Reset did not finish: ' . $e->getMessage() . ' Fix that and call reset again.');
            return;
        }

        $this->app->json([
            'status' => 'reset',
            'deleted' => $deleted,
            'message' => 'Everything has been cleared. The register is empty and ready for new documents.',
        ]);
    }

    /**
     * processing: something is queued or running; waiting_to_start: only
     * uploaded documents nobody asked to process; idle: nothing pending
     * (failed documents do not block, they need a manual retry).
     *
     * @param array{uploaded:int,queued:int,processing:int,failed:int} $counts
     */
    private function overallState(array $counts): string
    {
        if ($counts[LicenceQueue::QUEUED] + $counts[LicenceQueue::PROCESSING] > 0) {
            return 'processing';
        }

        return $counts[LicenceRegister::UPLOADED] > 0 ? 'waiting_to_start' : 'idle';
    }

    private function error(int $status, string $code, string $message): void
    {
        $this->app->json(['error' => $code, 'message' => $message], $status);
    }

    /**
     * Every file in a multipart request, whatever the field name.
     *
     * @return array<int,UploadedFile>
     */
    private function multipartFiles(): array
    {
        $files = [];
        foreach ($this->app->request()->getUploadedFiles() as $field) {
            foreach (is_array($field) ? $field : [$field] as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Files from a JSON body. Accepted shapes:
     *   {"files": [{"name": "a.pdf", "content": "<base64>"}, ...]}
     *   {"name": "a.pdf", "content": "<base64>"}
     *   [{"name": "a.pdf", "content": "<base64>"}, ...]
     * "content" may also be "contentBytes" (Outlook attachments) or a Power
     * Automate file object {"$content-type": "...", "$content": "<base64>"}.
     *
     * @return array<int,array{name:string,contents:?string}> Keyed by 1-based position
     */
    private function jsonFiles(): array
    {
        $request = $this->app->request();
        if (strpos(strtolower($request->type), 'application/json') !== 0) {
            return [];
        }

        $data = $request->data->getData();
        if (isset($data['files']) && is_array($data['files'])) {
            $items = $data['files'];
        } elseif (array_is_list($data)) {
            $items = $data;
        } elseif (isset($data['name']) || isset($data['content']) || isset($data['contentBytes'])) {
            $items = [$data];
        } else {
            $items = [];
        }

        $files = [];
        $position = 0;
        foreach ($items as $item) {
            $position++;
            $item = is_array($item) ? $item : [];
            $name = $item['name'] ?? '';
            $files[$position] = [
                'name' => is_string($name) ? trim($name) : '',
                'contents' => self::decodeBase64($item['content'] ?? $item['contentBytes'] ?? null),
            ];
        }

        return $files;
    }

    /**
     * @param mixed $content Base64 string, data: URL, or {"$content": "<base64>"}
     */
    private static function decodeBase64($content): ?string
    {
        if (is_array($content)) {
            $content = $content['$content'] ?? null;
        }
        if (!is_string($content)) {
            return null;
        }

        $content = (string) preg_replace('/^data:[^,]*,/', '', trim($content));
        $bytes = base64_decode((string) preg_replace('/\s+/', '', $content), true);

        return $bytes === false || $bytes === '' ? null : $bytes;
    }
}
