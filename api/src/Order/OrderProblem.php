<?php

declare(strict_types=1);

namespace App\Order;

/**
 * A business-rule error of checkout or order handling: HTTP status, a machine-readable code and extra fields,
 * rendered as a Problem ({message, code, ...}).
 */
final class OrderProblem extends \RuntimeException
{
    /** @param array<string, mixed> $extra */
    public function __construct(string $message, public readonly int $status, public readonly string $errorCode, public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    /** A request field that the attribute validation can't check, reported like a validation error. */
    public static function invalid(string $field, string $message): self
    {
        return new self($message, 422, 'invalid_field', ['violations' => [['field' => $field, 'message' => $message]]]);
    }

    public static function unprocessable(string $code, string $message, array $extra = []): self
    {
        return new self($message, 422, $code, $extra);
    }

    public static function conflict(string $code, string $message, array $extra = []): self
    {
        return new self($message, 409, $code, $extra);
    }

    public static function notAllowed(string $action, string $message): self
    {
        return new self($message, 409, 'action_not_allowed', ['action' => $action]);
    }

    public static function notFound(string $message): self
    {
        return new self($message, 404, 'not_found');
    }

    public function toArray(): array
    {
        return ['message' => $this->getMessage(), 'code' => $this->errorCode] + $this->extra;
    }
}
