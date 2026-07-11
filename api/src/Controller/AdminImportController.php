<?php

declare(strict_types=1);

namespace App\Controller;

use App\Import\FeedRefresh;
use App\Import\ImportBindings;
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
        private ImportBindings $bindings,
        private FeedRefresh $refresh,
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
        $feedUrl = array_key_exists('feedUrl', $payload) ? $payload['feedUrl'] : null;
        if ($feedUrl !== null && !is_string($feedUrl)) {
            return $this->json(['message' => 'feedUrl must be a string'], 422);
        }

        try {
            $saved = $this->settings->save($supplier, (int) $payload['inboundShippingMinor'], $feedUrl);
        } catch (\InvalidArgumentException $error) {
            return $this->json(['message' => $error->getMessage()], 422);
        }

        return $this->json($saved);
    }

    /** The manual feed-row → variant links (D03.3), of one variant or all of them. */
    #[Route('/bindings', methods: ['GET'])]
    public function bindings(Request $request): JsonResponse
    {
        return $this->json($this->bindings->list($request->query->get('variantId'), max(1, (int) $request->query->get('page', '1'))));
    }

    #[Route('/bindings', methods: ['POST'])]
    public function createBinding(Request $request): JsonResponse
    {
        if (($problem = $this->csrfProblem($request)) !== null) {
            return $problem;
        }
        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'A JSON body is required'], 422);
        }
        $variantId = $payload['variantId'] ?? null;
        $supplierSku = $payload['supplierSku'] ?? null;
        if (!is_string($variantId) || !is_string($supplierSku) || !is_string($payload['supplier'] ?? null)) {
            return $this->json(['message' => 'supplier, supplierSku and variantId are required strings'], 422);
        }

        try {
            $created = $this->bindings->create($payload['supplier'], $supplierSku, $variantId, $this->getUser()?->getUserIdentifier() ?? 'system', new \DateTimeImmutable());
        } catch (\InvalidArgumentException $error) {
            return $this->json(['message' => $error->getMessage()], 422);
        } catch (\DomainException $error) {
            return $this->json(['message' => $error->getMessage()], 409);
        }

        return $created === null ? $this->json(['message' => 'Variant not found'], 404) : $this->json($created, 201);
    }

    #[Route('/bindings/{id}', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    public function deleteBinding(string $id, Request $request): JsonResponse
    {
        if (($problem = $this->csrfProblem($request)) !== null) {
            return $problem;
        }

        return $this->bindings->delete($id) ? $this->json(['deleted' => true]) : $this->json(['message' => 'Binding not found'], 404);
    }

    /** The batches of one run, the feed-refresh error journal the admin works from. */
    #[Route('/batches', methods: ['GET'])]
    public function batches(Request $request): JsonResponse
    {
        $runId = (string) $request->query->get('runId', '');
        if (!\Symfony\Component\Uid\Uuid::isValid($runId)) {
            return $this->json(['message' => 'runId must be a UUID'], 422);
        }

        return $this->json(['items' => $this->refresh->batchSummaries($runId)]);
    }

    /** The manual restart of a failed batch (D03.4): replays exactly the stored batch plan. */
    #[Route('/batches/{id}/retry', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function retryBatch(string $id, Request $request): JsonResponse
    {
        if (($problem = $this->csrfProblem($request)) !== null) {
            return $problem;
        }
        try {
            $batch = $this->refresh->retryBatch($id);
        } catch (\DomainException $error) {
            return $this->json(['message' => $error->getMessage()], 409);
        }

        return $batch === null
            ? $this->json(['message' => 'Batch not found'], 404)
            : $this->json(['items' => $this->refresh->batchSummaries((string) $batch->getRun()->getId()->toRfc4122())]);
    }

    private function csrfProblem(Request $request): ?JsonResponse
    {
        return $this->csrf->isTokenValid(new CsrfToken('admin-write', $request->headers->get('X-CSRF-Token', '')))
            ? null
            : $this->json(['message' => 'Invalid CSRF token'], 403);
    }
}
