<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Model\PixieContext;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Project Row -> Index Model -> normalized array for Meili.
 */
final class PixieDocumentProjector
{
    public function __construct(
        private readonly IndexModelResolver $models,
        private readonly RowToIndexModelMapper $mapper,
        private readonly NormalizerInterface $normalizer,
        private readonly LocaleContext $localeContext,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function project(PixieContext $ctx, Row $row, string $indexedLocale): array
    {
        $pixieCode = $ctx->pixieCode;
        $config = $ctx->config;
        $sourceLocale = $config->getSourceLocale($this->localeContext->getDefault());

        $resolved = $this->models->resolve($pixieCode);
        $class = $resolved['class'];
        $persisted = $resolved['persisted'];

        $model = $this->mapper->map(
            modelClass: $class,
            persistedFields: $persisted,
            ctx: $ctx,
            row: $row,
            indexedLocale: $indexedLocale,
            sourceLocale: $sourceLocale
        );

        // Normalize model to array (what Meili receives)
        $doc = $this->normalizer->normalize($model, 'array');

        // Safety: ensure PK always present
        if (!isset($doc['id'])) {
            $doc['id'] = (string) $row->id;
        }

        return is_array($doc) ? $doc : [];
    }
}
