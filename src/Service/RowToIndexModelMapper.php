<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Model\PixieContext;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

final class RowToIndexModelMapper
{
    public function __construct(private readonly PropertyAccessorInterface $accessor) {}

    /**
     * @param class-string $modelClass
     * @param list<string> $persistedFields
     */
    public function map(
        string $modelClass,
        array $persistedFields,
        PixieContext $ctx,
        Row $row,
        string $indexedLocale,
        string $sourceLocale,
    ): object {
        $model = new $modelClass();

        // Always set system/meta if present on the model
        $this->setIfWritable($model, 'pixie', $ctx->pixieCode);
        $this->setIfWritable($model, 'core', $row->core->code ?? (string)$row->core);
        $this->setIfWritable($model, '_meta', ['sourceLocale' => $sourceLocale, 'indexedLocale' => $indexedLocale]);

        // Payload fields
        $payload = is_array($row->data ?? null) ? $row->data : [];

        foreach ($persistedFields as $field) {
            if ($field === '' || $field === '_meta') {
                continue;
            }

            // id is often Row.id; allow payload override if present
            if ($field === 'id') {
                $this->setIfWritable($model, 'id', (string)($payload['id'] ?? $row->id));
                continue;
            }

            if (array_key_exists($field, $payload)) {
                $value = $payload[$field];

                // If model property is array but value is scalar, coerce to list
                $value = $this->coerceForModel($model, $field, $value);

                $this->setIfWritable($model, $field, $value);
            }
        }

        // Overlay translations/hooks for fields referenced by strCodes (label/description/etc.)
        foreach (array_keys($row->getStrCodeMap()) as $field) {
            if ($this->accessor->isReadable($row, $field)) {
                $this->setIfWritable($model, $field, $this->accessor->getValue($row, $field));
            }
        }

        return $model;
    }

    private function setIfWritable(object $obj, string $field, mixed $value): void
    {
        if ($this->accessor->isWritable($obj, $field)) {
            $this->accessor->setValue($obj, $field, $value);
        }
    }

    private function coerceForModel(object $obj, string $field, mixed $value): mixed
    {
        try {
            $rp = new \ReflectionProperty($obj, $field);
            $t = $rp->getType();
            if ($t instanceof \ReflectionNamedType && $t->isBuiltin() && $t->getName() === 'array') {
                if (is_string($value)) {
                    return [$value];
                }
                if ($value === null) {
                    return [];
                }
            }
        } catch (\Throwable) {
            // ignore
        }
        return $value;
    }
}
