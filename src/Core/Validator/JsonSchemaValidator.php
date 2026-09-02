<?php

declare(strict_types=1);

namespace App\Core\Validator;

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator as JsonValidator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class JsonSchemaValidator
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    /**
     * Validate data against a bundle JSON Schema file.
     *
     * @param mixed  $data       PHP data to validate (array|null)
     * @param string $schemaName Bundle-qualified name, e.g. "Store/StoreSettings", "Store/StoreAddress"
     *
     * @throws JsonSchemaViolationException
     */
    public function validate(mixed $data, string $schemaName): void
    {
        if ($data === null) {
            return;
        }

        $schemaPath = $this->resolveSchemaPath($schemaName);
        if (!is_file($schemaPath)) {
            throw new \InvalidArgumentException(sprintf('JSON Schema not found: %s (%s)', $schemaName, $schemaPath));
        }

        $schemaData = json_decode((string) file_get_contents($schemaPath));
        if ($schemaData === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(sprintf('Invalid JSON Schema file %s: %s', $schemaPath, json_last_error_msg()));
        }

        // Convert PHP array to object for json-schema validation (associative arrays become objects)
        $dataForValidation = json_decode((string) json_encode($data));
        if ($dataForValidation === null) {
            $dataForValidation = $data;
        }

        $validator = new JsonValidator();
        $validator->validate($dataForValidation, $schemaData, Constraint::CHECK_MODE_NORMAL);

        if ($validator->isValid()) {
            return;
        }

        $violations = [];
        foreach ($validator->getErrors() as $error) {
            $property = $error['property'] !== '' ? $error['property'] : $schemaName;
            // Normalize property path: e.g. "order.requireAcceptance" for nested
            $violations[] = new JsonSchemaViolation($property, $error['message']);
        }

        $first = $violations[0] ?? null;
        $message = $first !== null ? sprintf('%s: %s', $first->property, $first->message) : 'JSON Schema validation failed';

        throw new JsonSchemaViolationException($message, $violations);
    }

    /**
     * Validate raw data against an inline schema array (decoded JSON).
     *
     * @param array<string, mixed> $inlineSchema
     *
     * @throws JsonSchemaViolationException
     */
    public function validateInline(mixed $data, array $inlineSchema): void
    {
        if ($data === null) {
            return;
        }

        $schemaData = json_decode((string) json_encode($inlineSchema));
        $dataForValidation = json_decode((string) json_encode($data));

        $validator = new JsonValidator();
        $validator->validate($dataForValidation, $schemaData, Constraint::CHECK_MODE_NORMAL);

        if ($validator->isValid()) {
            return;
        }

        $violations = [];
        foreach ($validator->getErrors() as $error) {
            $property = $error['property'] !== '' ? $error['property'] : 'value';
            $violations[] = new JsonSchemaViolation($property, $error['message']);
        }

        $first = $violations[0] ?? null;
        $message = $first !== null ? sprintf('%s: %s', $first->property, $first->message) : 'JSON Schema validation failed';

        throw new JsonSchemaViolationException($message, $violations);
    }

    private function resolveSchemaPath(string $schemaName): string
    {
        // schemaName: "Store/StoreAddress" -> src/Store/Resources/JsonSchema/StoreAddress.json
        // Also supports "Store/StoreSettings" etc.
        $parts = explode('/', $schemaName, 2);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException(sprintf('Schema name must be \"Bundle/Name\", got \"%s\"', $schemaName));
        }

        [$bundle, $name] = $parts;

        // Try src/{Bundle}/Resources/JsonSchema/{Name}.json
        $candidate = $this->projectDir . '/src/' . $bundle . '/Resources/JsonSchema/' . $name . '.json';
        if (is_file($candidate)) {
            return $candidate;
        }

        // Fallback: src/Core/Resources/JsonSchema (for shared schemas)
        $fallback = $this->projectDir . '/src/Core/Resources/JsonSchema/' . $name . '.json';
        if (is_file($fallback)) {
            return $fallback;
        }

        return $candidate;
    }
}
