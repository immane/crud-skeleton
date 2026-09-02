<?php

declare(strict_types=1);

namespace App\Core\Validator;

use Symfony\Component\Validator\Exception\ValidatorException;

final class JsonSchemaViolationException extends ValidatorException
{
    /**
     * @param list<JsonSchemaViolation> $violations
     */
    public function __construct(
        string $message,
        public readonly array $violations = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $map = [];
        foreach ($this->violations as $v) {
            $map[$v->property] = $v->message;
        }

        return $map;
    }
}
