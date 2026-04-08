<?php
declare(strict_types=1);

namespace Survos\PixieBundle\EventListener;

use Survos\ImportBundle\Event\ImportConvertRowEvent;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Service\Enhance\PixieEnhancer;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class PixieEnhanceRecordListener
{
    public function __construct(
        private readonly PixieService $pixie,
        private readonly PixieEnhancer $enhancer,
    ) {
    }


//    #[AsEventListener(event: ImportConvertRowEvent::class)]
    public function onRow(ImportConvertRowEvent $event): void
    {
        // Dataset is the pixie code in your usage: --dataset=larco
        $pixieCode = $event->dataset ? (string) $event->dataset : '';
        if ($pixieCode === '' || $event->row === null) {
            return;
        }

        $core = $this->extractCore($event->tags) ?? 'obj';

        try {
            $ctx = $this->pixie->getReference($pixieCode);
        } catch (\Throwable) {
            $pixieCode = $this->derivePixieCode($pixieCode);
            try {
                $ctx = $this->pixie->getReference($pixieCode);
            } catch (\Throwable) {
                // Pixie DB doesn't exist yet for this dataset — skip enhancement.
                // This is normal when running import:convert before pixie:ingest.
                return;
            }
        }
        $cfg = $ctx->config;

        $table = $cfg->getTable($core);
        if (!$table) {
            // No table config => no enhancements
            return;
        }

        $event->row = $this->enhancer->enhance(
            pixieCode: $pixieCode,
            core: $core,
            table: $table,
            row: $event->row
        );
    }

    /**
     * Tags come from import:convert --tags=... (comma separated).
     * We accept core:obj or core=obj.
     *
     * @param list<string> $tags
     */
    private function extractCore(array $tags): ?string
    {
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '') {
                continue;
            }
            if (str_starts_with($tag, 'core:')) {
                $v = substr($tag, 5);
                return $v !== '' ? $v : null;
            }
            if (str_starts_with($tag, 'core=')) {
                $v = substr($tag, 5);
                return $v !== '' ? $v : null;
            }
        }

        return null;
    }

    private function derivePixieCode(string $dataset): string
    {
        $dataset = trim($dataset);
        if (str_contains($dataset, '/')) {
            $parts = explode('/', $dataset);
            $last = end($parts);

            return is_string($last) && $last !== '' ? $last : $dataset;
        }

        if (str_contains($dataset, '-')) {
            $parts = explode('-', $dataset);
            $last = end($parts);

            return is_string($last) && $last !== '' ? $last : $dataset;
        }

        return $dataset;
    }
}
