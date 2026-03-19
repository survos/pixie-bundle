<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ArrayParameterType;

final class PixieBabelBrowser
{
    public function __construct(
        private readonly PixieService $pixie,
        private readonly Connection $defaultConnection, // app DB (Babel tables live here)
    ) {}

    /**
     * @return array{
     *   rows:list<array{
     *     field:string,
     *     code:string,
     *     source:?string,
     *     translation:?string,
     *     status:string
     *   }>,
     *   totals:array{codes:int, translated:int, missing:int}
     * }
     */
    public function browse(
        string $pixieCode,
        ?string $table = null,
        ?string $field = null,
        string $locale = 'es',
        int $limit = 0,
        bool $missingOnly = false,
    ): array {
        $ctx = $this->pixie->getReference($pixieCode);
        $pixieConn = $this->resolvePixieConnection($ctx);

        // 1) collect codes from pixie sqlite
        $codesByField = $this->collectCodes($pixieConn, $table, $field, $limit);
        $allCodes = array_values(array_unique(array_values($codesByField)));

        if ($allCodes === []) {
            return ['rows' => [], 'totals' => ['codes' => 0, 'translated' => 0, 'missing' => 0]];
        }

        // 2) query Babel (app DB) for sources + translations
        // Adjust table/column names if yours differ:
        // - str: code, source
        // - str_tr: str_code, target_locale, text
        $sql = <<<SQL
SELECT
  s.code AS code,
  s.source AS source,
  tr.text AS translation
FROM str s
LEFT JOIN str_tr tr
  ON tr.str_code = s.code AND tr.target_locale = :locale
WHERE s.code IN (:codes)
SQL;

        $map = [];
        $stmt = $this->defaultConnection->executeQuery(
            $sql,
            ['locale' => $locale, 'codes' => $allCodes],
            ['codes' => ArrayParameterType::STRING]
        );

        while ($r = $stmt->fetchAssociative()) {
            $map[$r['code']] = [
                'source' => $r['source'] ?? null,
                'translation' => $r['translation'] ?? null,
            ];
        }

        // 3) render rows
        $rows = [];
        $translated = 0;
        $missing = 0;

        foreach ($codesByField as $f => $code) {
            $source = $map[$code]['source'] ?? null;
            $translation = $map[$code]['translation'] ?? null;

            $ok = is_string($translation) && $translation !== '';
            $status = $ok ? 'OK' : 'MISSING';

            if ($ok) {
                $translated++;
            } else {
                $missing++;
            }

            if ($missingOnly && $ok) {
                continue;
            }

            $rows[] = [
                'field' => $f,
                'code' => $code,
                'source' => $source,
                'translation' => $translation,
                'status' => $status,
            ];
        }

        return [
            'rows' => $rows,
            'totals' => ['codes' => count($codesByField), 'translated' => $translated, 'missing' => $missing],
        ];
    }

    /**
     * @return array<string,string> field => code
     */
    private function collectCodes(Connection $pixieConn, ?string $table, ?string $field, int $limit): array
    {
        // This assumes:
        // - pixie_row has a JSON column "str_codes" like {"label":"<code>", ...}
        // - optionally a column like "table_name" (or similar) to filter by core/table
        $where = [];
        $params = [];
        if ($table) {
            $where[] = 'r.table_name = :table';
            $params['table'] = $table;
        }

        $sql = 'SELECT r.str_codes FROM pixie_row r';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $codesByField = [];
        $stmt = $pixieConn->executeQuery($sql, $params);

        while ($row = $stmt->fetchAssociative()) {
            $json = $row['str_codes'] ?? null;
            if (!is_string($json) || $json === '') {
                continue;
            }
            $map = json_decode($json, true);
            if (!is_array($map)) {
                continue;
            }

            foreach ($map as $f => $code) {
                if (!is_string($f) || !is_string($code) || $code === '') {
                    continue;
                }
                if ($field && $f !== $field) {
                    continue;
                }
                // first wins is fine; we want a browse list, not every row
                $codesByField[$f] ??= $code;
            }
        }

        ksort($codesByField);
        return $codesByField;
    }

    private function resolvePixieConnection(object $ctx): Connection
    {
        // Common patterns:
        // - $ctx->getConnection(): Connection
        // - $ctx->connection: Connection
        // - $ctx->em / $ctx->entityManager (Doctrine ORM EM) ->getConnection()
        if (method_exists($ctx, 'getConnection')) {
            $conn = $ctx->getConnection();
            if ($conn instanceof Connection) {
                return $conn;
            }
        }

        foreach (['connection', 'conn'] as $prop) {
            if (property_exists($ctx, $prop) && ($ctx->$prop instanceof Connection)) {
                return $ctx->$prop;
            }
        }

        foreach (['em', 'entityManager', 'orm', 'manager'] as $prop) {
            if (property_exists($ctx, $prop) && $ctx->$prop && method_exists($ctx->$prop, 'getConnection')) {
                $conn = $ctx->$prop->getConnection();
                if ($conn instanceof Connection) {
                    return $conn;
                }
            }
        }

        throw new \RuntimeException(sprintf(
            'Unable to resolve Pixie DBAL Connection from %s. Add $ctx->getConnection() or expose $ctx->em->getConnection().',
            $ctx::class
        ));
    }
}
