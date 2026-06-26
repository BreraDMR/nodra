<?php

declare(strict_types=1);

namespace App\Controller;

use App\Import\ImportConflictException;
use App\Import\ImportService;
use App\Import\ImportSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/api/admin/imports')]
final class AdminImportController extends AbstractController
{
    public function __construct(
        private ImportService $imports,
        private ImportSettings $settings,
        private CsrfTokenManagerInterface $csrf,
    ) {}

    #[Route('/preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        if (($problem = $this->csrfProblem($request)) !== null) {
            return $problem;
        }
        $file = $request->files->get('file');
        if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile || !$file->isValid()) {
            return $this->json(['message' => 'Upload the feed file as multipart form data field "file"'], 422);
        }

        try {
            $report = $this->imports->preview($file->getClientOriginalName() ?: 'feed.csv', (string) $file->getContent());
        } catch (\InvalidArgumentException $error) {
            return $this->json(['message' => $error->getMessage()], 422);
        }

        return $this->json($report);
    }

    #[Route('/apply', methods: ['POST'])]
    public function apply(Request $request): JsonResponse
    {
        if (($problem = $this->csrfProblem($request)) !== null) {
            return $problem;
        }
        $file = $request->files->get('file');
        $runId = trim((string) $request->request->get('runId', ''));
        if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile || !$file->isValid()) {
            return $this->json(['message' => 'Upload the same feed file again as multipart field "file"'], 422);
        }
        if ($runId === '') {
            return $this->json(['message' => 'The runId of the previewed import is required'], 422);
        }

        try {
            $result = $this->imports->apply($runId, (string) $file->getContent());
        } catch (\InvalidArgumentException $error) {
            return $this->json(['message' => $error->getMessage()], 422);
        } catch (ImportConflictException $error) {
            return $this->json(['message' => $error->getMessage(), 'code' => $error->errorCode], 409);
        }

        return $result === null ? $this->json(['message' => 'Import run not found'], 404) : $this->json($result);
    }

    #[Route('/runs', methods: ['GET'])]
    public function runs(Request $request): JsonResponse
    {
        return $this->json($this->imports->runs(max(1, (int) $request->query->get('page', '1'))));
    }

    #[Route('/runs/{id}', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function run(string $id): JsonResponse
    {
        return $this->imports->run($id) === null
            ? $this->json(['message' => 'Import run not found'], 404)
            : $this->json($this->imports->run($id));
    }

    #[Route('/settings', methods: ['GET'])]
    public function settings(): JsonResponse
    {
        return $this->json(['settings' => $this->settings->list()]);
    }

    #[Route('/settings/{supplier}', methods: ['PUT'])]
    public function saveSetting(string $supplier, Request $request): JsonResponse
    {
        if (($problem = $this->csrfProblem($request)) !== null) {
            return $problem;
        }
        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload) || !is_numeric($payload['inboundShippingMinor'] ?? null)) {
            return $this->json(['message' => 'inboundShippingMinor must be a non-negative number'], 422);
        }

        try {
            $saved = $this->settings->save($supplier, (int) $payload['inboundShippingMinor']);
        } catch (\InvalidArgumentException $error) {
            return $this->json(['message' => $error->getMessage()], 422);
        }

        return $this->json($saved);
    }

    private function csrfProblem(Request $request): ?JsonResponse
    {
        return $this->csrf->isTokenValid(new CsrfToken('admin-write', $request->headers->get('X-CSRF-Token', '')))
            ? null
            : $this->json(['message' => 'Invalid CSRF token'], 403);
    }
}
