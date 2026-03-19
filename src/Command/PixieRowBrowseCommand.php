<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Service\LocaleContext;
use Survos\PixieBundle\Service\PixieDocumentProjector;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('pixie:row:browse', 'Browse Row data; defaults to Meili-ready JSON documents (projected per locale)')]
final class PixieRowBrowseCommand
{
    public function __construct(
        private readonly PixieService $pixie,
        private readonly PixieDocumentProjector $projector,
        private readonly LocaleContext $localeContext,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('pixie-code')] string $pixieCode,
        #[Argument('table-name')] string $tableName = Row::class, // keep your existing semantics if different
        #[Option('core')] string $core = 'obj',
        #[Option('Locale to browse/project')] ?string $locale = null,

        // Output modes
        #[Option('Output projected Meili-ready JSON (default true)')] bool $json = true,
        #[Option('Pretty-print JSON (default true)')] bool $pretty = true,
        #[Option('Wrap output in a JSON array (default: auto based on limit)')] ?bool $asArray = null,

        // Filtering/paging
        #[Option('fields (comma-separated) to include in JSON output; default: all strCode fields')] ?string $fields = null,
        #[Option('limit')] int $limit = 10,
        #[Option('offset')] int $offset = 0,
        #[Option('Row id (e.g. obj-1)')] ?string $id = null,
        #[Option('Dump Row entity for debugging')] bool $dump = false,
    ): int {
        $ctx = $this->pixie->getReference($pixieCode);
        $config = $ctx->config;

        $sourceLocale = $config->getSourceLocale($this->localeContext->getDefault());
        $locale ??= $sourceLocale;

        $owner = $ctx->ownerRef;
        $coreE = $this->pixie->getCore($core, $owner);

        $criteria = ['core' => $coreE];
        if ($id) {
            $criteria['id'] = $id;
        }

        $rows = $ctx->repo(Row::class)->findBy($criteria, ['id' => 'ASC'], $limit ?: null, $offset);

        $includeFields = null;
        if ($fields) {
            $includeFields = array_values(array_filter(array_map('trim', explode(',', $fields))));
        }

        $docs = [];

        $this->localeContext->run($locale, function () use ($rows, $ctx, $locale, $dump, $includeFields, &$docs): void {
            foreach ($rows as $row) {
                $dump && dump($row);

                $doc = $this->projector->project($ctx, $row, $locale);

                // Optional field filter: keep meta/id/core/pixie but filter dynamic fields
                if ($includeFields !== null) {
                    $keep = ['id' => true, 'pixie' => true, 'core' => true, '_meta' => true];
                    $filtered = [];
                    foreach ($doc as $k => $v) {
                        if (isset($keep[$k]) || in_array($k, $includeFields, true)) {
                            $filtered[$k] = $v;
                        }
                    }
                    $doc = $filtered;
                }

                $docs[] = $doc;
            }
        });

        if (!$json) {
            // You can keep your old tabular output here if you still want it.
            $io->warning('Non-JSON output mode not implemented in this snippet; set --json=1 to use default.');
            return Command::SUCCESS;
        }

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        // Output decision: single object vs array
        $asArray ??= (count($docs) !== 1);

        $payload = $asArray ? $docs : ($docs[0] ?? null);

        $io->writeln(json_encode($payload, $flags) ?: 'null');

        return Command::SUCCESS;
    }
}
