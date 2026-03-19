<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Survos\DataBundle\Entity\DatasetInfo;
use Survos\PixieBundle\Model\Config;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Runtime registry of available Pixie databases.
 *
 * Sources (in priority order):
 *   1. DatasetInfo Doctrine entity (populated by data:scan-datasets) — preferred
 *   2. Compile-time YAML config (legacy, still works for YAML-configured pixies)
 *
 * The DatasetInfo source knows about ALL datasets on disk regardless of whether
 * they have a YAML config file. This is the no-YAML path forward.
 */
final class PixieConfigRegistry
{
    /** @var array<string,Config> */
    private array $cache = [];

    /** Doctrine EM for DatasetInfo lookups — injected via #[Required] */
    private ?\Doctrine\ORM\EntityManagerInterface $em = null;

    /**
     * @param array<string,mixed> $pixiesConfig keyed by pixieCode (YAML legacy)
     */
    public function __construct(
        private readonly ?DenormalizerInterface $denormalizer = null,
        private readonly array $pixiesConfig = [],
    ) {}

    #[Required]
    public function setEntityManager(\Doctrine\ORM\EntityManagerInterface $em): void
    {
        $this->em = $em;
    }

    /**
     * All available pixies — from DatasetInfo (preferred) + YAML config (legacy).
     *
     * @return array<string,Config>
     */
    public function all(): array
    {
        // DatasetInfo: all datasets that have a pixie DB on disk
        if ($this->em !== null) {
            try {
                $infos = $this->em->getRepository(DatasetInfo::class)
                    ->createQueryBuilder('d')
                    ->where('d.pixieDbPath IS NOT NULL')
                    ->orWhere('d.status IN (:statuses)')
                    ->setParameter('statuses', ['pixie', 'indexed', 'normalized', 'profiled'])
                    ->getQuery()
                    ->getResult();

                foreach ($infos as $info) {
                    $code = $info->pixieCode();
                    if (!isset($this->cache[$code])) {
                        $this->cache[$code] = $this->buildConfigFromDatasetInfo($info);
                    }
                }
            } catch (\Throwable) {
                // DatasetInfo table not yet created — fall through to YAML
            }
        }

        // YAML legacy: merge in anything not already in cache
        foreach (array_keys($this->pixiesConfig) as $code) {
            if (!isset($this->cache[$code])) {
                $config = $this->get($code);
                if ($config) {
                    $this->cache[$code] = $config;
                }
            }
        }

        return $this->cache;
    }

    public function get(string $pixieCode): ?Config
    {
        $pixieCode = trim($pixieCode);
        if ($pixieCode === '') {
            return null;
        }

        if (isset($this->cache[$pixieCode])) {
            return $this->cache[$pixieCode];
        }

        // Try DatasetInfo first
        if ($this->em !== null) {
            try {
                // pixieCode "fortepan_hu" → datasetKey "fortepan/hu"
                $datasetKey = str_replace('_', '/', $pixieCode);
                $info = $this->em->getRepository(DatasetInfo::class)->find($datasetKey)
                    ?? $this->em->getRepository(DatasetInfo::class)->find($pixieCode);

                if ($info) {
                    return $this->cache[$pixieCode] = $this->buildConfigFromDatasetInfo($info);
                }
            } catch (\Throwable) {}
        }

        // YAML fallback
        $configArray = $this->pixiesConfig[$pixieCode] ?? null;
        if (!is_array($configArray)) {
            return null;
        }

        if (!$this->denormalizer) {
            throw new \RuntimeException('PixieConfigRegistry requires a DenormalizerInterface to return Config objects.');
        }

        /** @var Config $config */
        $config = $this->denormalizer->denormalize($configArray, Config::class);
        $config->code = $pixieCode;

        return $this->cache[$pixieCode] = $config;
    }

    /** @return string[] */
    public function codes(): array
    {
        $codes = array_merge(
            array_keys($this->pixiesConfig),
            array_keys($this->cache),
        );
        $codes = array_unique($codes);
        sort($codes);
        return $codes;
    }

    public function has(string $pixieCode): bool
    {
        return $this->get($pixieCode) !== null;
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->pixiesConfig;
    }

    private function buildConfigFromDatasetInfo(DatasetInfo $info): Config
    {
        $config = new Config();
        $config->code = $info->pixieCode();

        // Populate source block from meta
        $source = new \Survos\PixieBundle\Model\Source();
        $source->label       = $info->label ?? $info->pixieCode();
        $source->description = $info->description;
        $source->origin      = $info->aggregator ?? 'data';
        $config->source = $source;

        // Cores → tables
        foreach ($info->cores as $coreName) {
            $table = new \Survos\PixieBundle\Model\Table();
            $table->setPkName('id');
            // Field names from profile
            $fieldNames = $info->fields[$coreName] ?? [];
            $table->setProperties(array_map(
                static fn(string $f) => new \Survos\PixieBundle\Model\Property($f),
                $fieldNames
            ));
            $config->tables[$coreName] = $table;
        }

        return $config;
    }
}
