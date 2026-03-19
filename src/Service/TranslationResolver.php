<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Runtime\BabelSchema;
use Survos\Lingua\Core\Identity\HashUtil;

final class TranslationResolver
{
    public function __construct(
        // IMPORTANT: this must be the app DB connection (default), not the pixie sqlite connection.
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Resolve translated texts for a set of Babel Str.code values.
     *
     * Returns: code => translatedText (or source fallback if translation missing)
     *
     * @param list<string> $codes
     * @return array<string,string>
     */
    public function textsFor(array $codes, string $targetLocale): array
    {
        $codes = array_values(array_unique(array_filter(array_map('strval', $codes))));
        if ($codes === []) {
            return [];
        }

        $targetLocale = HashUtil::normalizeLocale($targetLocale);
        if ($targetLocale === '') {
            return [];
        }

        // Query translation text first; fall back to source if missing.
        $sql = sprintf(
            'SELECT s.%s AS code,
                    s.%s AS source,
                    tr.%s AS tr_text
             FROM %s s
             LEFT JOIN %s tr
               ON tr.%s = s.%s AND tr.%s = :loc
             WHERE s.%s IN (:codes)',
            BabelSchema::STR_CODE,
            BabelSchema::STR_SOURCE,
            BabelSchema::STR_TR_TEXT,
            BabelSchema::STR_TABLE,
            BabelSchema::STR_TR_TABLE,
            BabelSchema::STR_TR_STR_CODE,
            BabelSchema::STR_CODE,
            BabelSchema::STR_TR_TARGET_LOCALE,
            BabelSchema::STR_CODE
        );

        $rows = $this->connection->fetchAllAssociative(
            $sql,
            ['loc' => $targetLocale, 'codes' => $codes],
            ['codes' => ArrayParameterType::STRING]
        );

        $out = [];
        foreach ($rows as $r) {
            $code = (string) ($r['code'] ?? '');
            if ($code === '') {
                continue;
            }

            $tr = $r['tr_text'] ?? null;
            $tr = is_string($tr) ? trim($tr) : '';

            if ($tr !== '') {
                $out[$code] = $tr;
                continue;
            }

            $src = $r['source'] ?? null;
            $src = is_string($src) ? trim($src) : '';
            if ($src !== '') {
                $out[$code] = $src; // fallback
            }
        }

        $this->logger->debug('TranslationResolver textsFor', [
            'targetLocale' => $targetLocale,
            'requested' => count($codes),
            'returned' => count($out),
            'sample_code' => $codes[0] ?? null,
            'sample_text' => $codes[0] && isset($out[$codes[0]]) ? $out[$codes[0]] : null,
        ]);

        return $out;
    }
}
