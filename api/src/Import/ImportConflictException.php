<?php

declare(strict_types=1);

namespace App\Import;

/** An apply that doesn't match its preview: the file changed, or the run was already applied. Nothing was written. */
final class ImportConflictException extends \DomainException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
