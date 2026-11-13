<?php
declare(strict_types=1);

namespace Ramonak;

/** A refusal an operation answers with: a stable code an agent can act on, and a sentence saying why. */
final class OperationError extends \RuntimeException
{
    public function __construct(public readonly string $error, string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}
