<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\Event;
use Survos\PixieBundle\Entity\EventDefinition;
use Survos\PixieBundle\Entity\EventParticipant;
use Survos\PixieBundle\Entity\EventRoleDefinition;
use Survos\PixieBundle\Entity\CoreDefinition;
use Survos\PixieBundle\Entity\FieldDefinition;
use Survos\PixieBundle\Entity\Inst;
use Survos\PixieBundle\Entity\OriginalImage;
use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Entity\RowImportState;
use Survos\PixieBundle\Entity\StatFacet;
use Survos\PixieBundle\Entity\StatProperty;
use Survos\PixieBundle\Entity\Term;
use Survos\PixieBundle\Entity\TermSet;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Model\PixieContext;
use Survos\PixieBundle\Model\Property;

class PixieService extends PixieServiceBase
{
    /**
     * Only these entities participate in Pixie SQLite schema creation.
     *
     * Anything not listed here is considered legacy/optional and must not
     * cause tables to be created implicitly.
     */
    private const array SCHEMA_ENTITIES = [
        Inst::class,
        Core::class,
        Row::class,
        // Keep if you still want images as a first-class persisted table.
        // If/when you decouple images from Row entirely, remove this from the allowlist.
        OriginalImage::class,

        // Compiled schema snapshot (derived from YAML rules/properties)
        CoreDefinition::class,
        FieldDefinition::class,

        TermSet::class,
        Term::class,

        // Stats tables (optional but used by pixie:show)
        StatProperty::class,
        StatFacet::class,

        // Events (museum-digital style multi-participant facts — opt-in)
        EventDefinition::class,
        EventRoleDefinition::class,
        Event::class,
        EventParticipant::class,

        // Import pipeline (optional)
        RowImportState::class,
    ];

    /**
     * Switch the shared pixie EM connection to the DB file for $pixieCode,
     * WITHOUT querying any tables. Safe to call before schema exists.
     */
    public function switchToPixieDatabase(string $pixieCode): EntityManagerInterface
    {
        $em = $this->pixieEntityManager;
        $conn = $em->getConnection();

        $targetPath = $this->dbName($pixieCode);
        $currentPath = $conn->getParams()['path'] ?? null;

        $this->logger?->info('Pixie DB switch', [
            'pixie' => $pixieCode,
            'target' => $targetPath,
            'current' => $currentPath,
            'exists' => file_exists($targetPath),
            'connected' => $conn->isConnected(),
        ]);

        if ($currentPath !== $targetPath) {
            // Avoid leaking managed entities across database boundaries.
            try {
                $em->flush();
            } catch (\Throwable) {
                // best-effort; do not block switching
            }

            $em->clear();

            if ($conn->isConnected()) {
                $conn->close();
            }

            $conn->selectDatabase($targetPath);

            // sanity check: forces connect
            try {
                $conn->executeQuery('SELECT 1')->fetchOne();
            } catch (\Throwable $e) {
                $this->logger?->error('Pixie DB connection test failed', [
                    'pixie' => $pixieCode,
                    'path' => $targetPath,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        $this->currentPixieCode = $pixieCode;
        return $em;
    }

    /**
     * Ensure the Pixie schema exists in the given EM.
     *
     * Idempotent:
     * - If the bootstrap table exists, return early.
     * - Otherwise create/update schema ONLY for SCHEMA_ENTITIES.
     */
    public function ensureSchema(EntityManagerInterface $em): void
    {
        // Keep this check (it has helped with autoload/mapping issues),
        // but it must not influence schema decisions beyond diagnostics.
        $this->checkOwnerEntityFile();

        $sm = $em->getConnection()->createSchemaManager();

        // Bootstrap check: owner table is our canonical indicator of "schema exists".
        if ($sm->tablesExist(['inst'])) {
            return;
        }

        $metadataFactory = $em->getMetadataFactory();

        $metas = [];
        $missing = [];

        foreach (self::SCHEMA_ENTITIES as $class) {
            // Some optional entities may not exist in a given installation
            // (e.g., RowImportState if removed). Skip cleanly.
            if (!class_exists($class)) {
                $missing[] = $class;
                continue;
            }

            $metas[] = $metadataFactory->getMetadataFor($class);
        }

        $names = array_map(fn($m) => $m->getName(), $metas);
        $diff = array_diff($names, self::SCHEMA_ENTITIES);
        if ($diff !== []) {
            throw new \RuntimeException('Pixie ensureSchema is not using allowlist. Unexpected metadata: '.implode(', ', $diff));
        }

        if ($missing !== []) {
            $this->logger?->info('Pixie schema allowlist: optional classes not present', [
                'missing' => $missing,
            ]);
        }

        // Safety: if Owner isn't in the metadata list, schema creation is meaningless.
        $hasOwner = false;
        foreach ($metas as $m) {
            if ($m->getName() === Inst::class) {
                $hasOwner = true;
                break;
            }
        }

        if (!$hasOwner) {
            throw new \RuntimeException('Pixie schema creation failed: Owner metadata not loaded.');
        }

        $tool = new SchemaTool($em);

        // Use saveMode=true (second arg) to avoid dropping anything unexpectedly.
        $tool->updateSchema($metas, true);

        // Optional diagnostic: confirm owner table now exists.
        if (!$sm->tablesExist(['inst'])) {
            throw new \RuntimeException('Pixie schema creation failed: owner table not created.');
        }
    }

    /**
     * Check if the Owner entity file exists and is loadable (diagnostic helper).
     */
    private function checkOwnerEntityFile(): void
    {
        if (!class_exists(Inst::class)) {
            $this->logger?->warning('Owner class not autoloadable', [
                'class' => Inst::class,
            ]);
            return;
        }

        try {
            $reflection = new \ReflectionClass(Inst::class);
            $ownerFile = $reflection->getFileName();
            if (!$ownerFile || !is_file($ownerFile)) {
                $this->logger?->warning('Owner reflected file missing', [
                    'file' => $ownerFile,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('Owner reflection failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get a PixieContext for the given pixie code.
     *
     * Notes:
     * - This must be called once per command/request entrypoint.
     * - Do NOT call inside loops; pass $ctx/$ctx->em instead.
     *
     * Behavior:
     * - By default, does NOT call ensureSchema() (web-safe, no side effects).
     * - By default, uses YAML-only config clone (no compiled schema reads).
     * - You can opt-in to ensureSchema and/or compiled schema merging per caller.
     */
    public function getReference(
        ?string $pixieCode = null,
        bool $ensureSchema = false,
        bool $useCompiledSchema = false,   // kept for back-compat, now always uses DB schema
        bool $requireConfig = false,        // kept for back-compat, no longer throws
    ): PixieContext {
        if (!$pixieCode) {
            $pixieCode = $this->currentPixieCode;
            if (!$pixieCode) {
                throw new \RuntimeException('No pixie code provided and no current pixie code set');
            }
        }

        $em = $this->switchToPixieDatabase($pixieCode);

        if ($ensureSchema) {
            $this->ensureSchema($em);
        }

        // Always build config from the compiled DB schema (CoreDefinition/FieldDefinition).
        // Schema originates from 00_meta/dataset.yaml + profile.json — no YAML config needed.
        // $useCompiledSchema kept for back-compat but is now always true.
        $config = $this->buildConfigSnapshot($pixieCode, $em);

        $this->currentPixieCode = $pixieCode;

        return new PixieContext($pixieCode, $config, $em);
    }

    /**
     * Build a Config from the compiled schema stored in the pixie DB
     * (CoreDefinition + FieldDefinition rows written by pixie:migrate).
     *
     * Falls back to a bare minimal Config when no compiled schema exists yet
     * (i.e. the DB was just created and pixie:migrate hasn't run fully).
     *
     * No YAML involved. Schema comes from: 00_meta/dataset.yaml + profile.json
     * (written into CoreDefinition/FieldDefinition by pixie:migrate).
     */
    private function buildConfigSnapshot(string $pixieCode, EntityManagerInterface $em): Config
    {
        $cfg = new Config();
        $cfg->code          = $pixieCode;
        $cfg->owner         = null;
        $cfg->pixieFilename = $this->getPixieFilename($pixieCode);
        $cfg->dataDir       = $this->resolveFilename('data', 'data');

        try {
            $coreDefs = $em->getRepository(CoreDefinition::class)
                ->findBy(['ownerCode' => $pixieCode], ['core' => 'ASC']);

            $tables = [];
            foreach ($coreDefs as $def) {
                $tName = $def->core;

                $fds = $em->getRepository(FieldDefinition::class)
                    ->findBy(['ownerCode' => $pixieCode, 'core' => $tName], ['position' => 'ASC', 'id' => 'ASC']);

                $props = [];
                foreach ($fds as $fd) {
                    $props[] = new Property($fd->code);
                }

                $t = new \Survos\PixieBundle\Model\Table();
                $t->setPkName($def->pk ?? 'id');
                $t->setProperties($props);
                $tables[$tName] = $t;
            }

            if ($tables) {
                $cfg->setTables($tables);
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('Could not load compiled schema from DB', [
                'pixie' => $pixieCode,
                'error' => $e->getMessage(),
            ]);
        }

        return $cfg;
    }

    /**
     * @return list<\Doctrine\ORM\Mapping\ClassMetadata>
     */
    private function getSchemaMetadata(EntityManagerInterface $em): array
    {
        $mf = $em->getMetadataFactory();
        $metas = [];

        foreach (self::SCHEMA_ENTITIES as $class) {
            if (!class_exists($class)) {
                continue;
            }
            $metas[] = $mf->getMetadataFor($class);
        }

        return $metas;
    }
}
