<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * HTTP errors under /api/ come back as JSON {"message": ...} whatever the Accept header says.
 * A body or query string that failed #[MapRequestPayload] / #[MapQueryString] is always a 422 with the
 * violations listed; Symfony's default for a bad query string would be 404.
 */
#[AsEventListener]
final class ApiExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $error = $event->getThrowable();
        if (!$error instanceof HttpExceptionInterface || !str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $failed = $error->getPrevious();
        if ($failed instanceof ValidationFailedException) {
            $violations = [];
            foreach ($failed->getViolations() as $violation) {
                $field = $violation->getPropertyPath();
                $violations[] = ['field' => $field === '' ? null : $field, 'message' => (string) $violation->getMessage()];
            }
            $message = implode(' ', array_map(static fn (array $v): string => ($v['field'] === null ? '' : $v['field'].': ').$v['message'], $violations));
            $event->setResponse(new JsonResponse(['message' => $message, 'violations' => $violations], Response::HTTP_UNPROCESSABLE_ENTITY));

            return;
        }

        // server errors keep Symfony's own handling, the debug page is more useful there
        $status = $error->getStatusCode();
        if ($status >= 500) {
            return;
        }
        $message = $error->getMessage() !== '' ? $error->getMessage() : (Response::$statusTexts[$status] ?? 'Request failed');
        $event->setResponse(new JsonResponse(['message' => $message], $status, $error->getHeaders()));
    }
}
