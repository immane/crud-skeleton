<?php

declare(strict_types=1);

namespace App\Core\Validator;

final readonly class JsonSchemaViolation
{
    public function __construct(
        public string $property,
        public string $message,
    ) {
    }
}
