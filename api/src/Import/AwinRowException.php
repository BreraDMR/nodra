<?php

declare(strict_types=1);

namespace App\Import;

/** One broken feed row; the parser turns it into an error row and carries on. */
final class AwinRowException extends \Exception
{
    public function __construct(public readonly int $rowNumber, string $message)
    {
        parent::__construct($message);
    }
}
