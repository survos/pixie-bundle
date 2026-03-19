<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Psr\Log\LoggerInterface;
use Survos\MeiliBundle\Service\IndexNameResolver as MeiliIndexNameResolver;
use Survos\MeiliBundle\Service\MeiliService;

final class MeiliIndexer
{
    public function __construct(
        private readonly MeiliService $meili,
        private readonly MeiliSettingsBuilder $settingsBuilder,
        private readonly MeiliIndexNameResolver $indexNames,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Build settings payload (pure; no network).
     */
    public function buildSettings(string $baseName, ?string $core = null): array
    {
        return $this->settingsBuilder->build($baseName, $core);
    }

    /**
     * Enqueue index creation (if needed) + enqueue updateSettings.
     * Never waits; safe for high-throughput pipelines.
     *
     * Returns the resolved UID (prefix applied).
     */
    public function ensureIndexReady(
        string $baseName,
        ?string $locale,
        ?string $core = null,
        string $primaryKey = 'id',
        ?string $fallbackSourceLocale = null,
    ): string {
        $fallbackSourceLocale ??= 'en';

        $isMlFor = $this->indexNames->isMultiLingualFor($baseName, $fallbackSourceLocale);
        $uid = $this->indexNames->uidFor($baseName, $locale, $isMlFor);

        // This should enqueue createIndex if missing (server task), and return the endpoint.
        $index = $this->meili->getOrCreateIndex($uid, $primaryKey, autoCreate: true, wait: false);

        $settings = $this->settingsBuilder->build($baseName, $core);

        try {
            // Enqueue settings update. Do not wait.
            $index->updateSettings($settings);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed applying Meili settings', [
                'base' => $baseName,
                'locale' => $locale,
                'core' => $core,
                'uid' => $uid,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $uid;
    }

    /**
     * Enqueue addDocuments. Do not wait.
     *
     * @param array<int,array<string,mixed>> $docs
     */
    public function indexDocs(string $uid, array $docs): void
    {
        if ($docs === []) {
            return;
        }
        $index = $this->meili->getIndexEndpoint($uid);
        $index->addDocuments($docs);
    }
}
