<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Model\Config;

/**
 * Authoritative schema lifecycle for Pixie tenant DBs.
 *
 * - ensureOrmSchema(): bootstrap ORM tables if missing (idempotent).
 * - migrateTenantSchemaToOrm(): reconcile tenant schema to ORM metadata (diff-based).
 *
 * This intentionally avoids Doctrine Migrations for per-tenant SQLite.
 */
final class PixieSchemaManager
{
    public function __construct(
        private readonly PixieEntityMetadataProvider $metadataProvider,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function ensureOrmSchema(EntityManagerInterface $em): void
    {
        $sm = $em->getConnection()->createSchemaManager();
        if ($sm->tablesExist(['inst'])) {
            return;
        }

        $classes = $this->metadataProvider->getPixieMetadata($em);
        (new SchemaTool($em))->updateSchema($classes, true);

        $this->logger?->info('Pixie ORM schema ensured', [
            'db' => $em->getConnection()->getParams()['path'] ?? null,
        ]);
    }

    public function migrateTenantSchemaToOrm(?Config $config, EntityManagerInterface $em, string $tenantDbPath): void
    {
        $code = $config ? (string) $config->code : basename($tenantDbPath, '.db');
        $this->logger?->info('Pixie schema diff start', [
            'pixieCode' => $code,
            'tenantDbPath' => $tenantDbPath,
        ]);

        $targetConn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path'   => $tenantDbPath,
        ]);

        $platform = $targetConn->getDatabasePlatform();
        $sm = $targetConn->createSchemaManager();

        $current = $sm->introspectSchema();

        $meta = $this->metadataProvider->getPixieMetadata($em);
        $desired = (new SchemaTool($em))->getSchemaFromMetadata($meta);

        $comparator = $sm->createComparator();
        $diff = $comparator->compareSchemas($current, $desired);

        if ($diff->isEmpty()) {
            $this->logger?->info('Pixie schema diff: no changes', ['pixieCode' => $code]);
            return;
        }

        $sql = $platform->getAlterSchemaSQL($diff);

        $this->logger?->info('Pixie schema diff: executing', [
            'pixieCode' => $code,
            'statements' => count($sql),
        ]);

        $targetConn->executeStatement('PRAGMA foreign_keys = OFF;');
        try {
            foreach ($sql as $ddl) {
                $this->logger?->debug('Pixie DDL', [
                    'pixieCode' => $code,
                    'ddl' => $ddl,
                ]);
                $targetConn->executeStatement($ddl);
            }
        } finally {
            $targetConn->executeStatement('PRAGMA foreign_keys = ON;');
        }

        $this->logger?->info('Pixie schema diff complete', ['pixieCode' => $code]);
    }
}
