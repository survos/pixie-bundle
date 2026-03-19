<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Survos\PixieBundle\Service\PixieConfigRegistry;
use Survos\PixieBundle\Service\TermCodeGenerator;
use Survos\PixieBundle\Service\TermSpecParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('pixie:terms:extract', 'Extract termsets/terms from a JSONL file using Pixie config (writes _terms/*.jsonl)')]
final class PixieTermsExtractCommand
{
    public function __construct(
        private readonly PixieConfigRegistry $registry,
        private readonly TermSpecParser $parser,
        private readonly TermCodeGenerator $codes,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('pixie code')] string $pixieCode,
        #[Argument('core (e.g. obj)')] string $core = 'obj',
        #[Option('Input JSONL (default: data/<pixie>/20_normalize/<core>.jsonl)')] ?string $in = null,
        #[Option('Output dir (default: data/<pixie>/20_normalize/_terms)')] ?string $outDir = null,
        #[Option('Limit lines (0 = all)')] int $limit = 0,
        #[Option('Overwrite existing _terms files')] bool $force = false,
    ): int {
        $config = $this->registry->get($pixieCode);
        if (!$config) {
            $io->error("Unknown pixie '$pixieCode' (registry returned null).");
            return Command::FAILURE;
        }

        $table = $config->getTable($core);
        if (!$table) {
            $io->error("No table '$core' in pixie '$pixieCode'.");
            return Command::FAILURE;
        }

        $base = rtrim((string)($config->dataDir ?? ("data/$pixieCode")), '/');
        $in ??= "$base/20_normalize/$core.jsonl";
        $outDir ??= "$base/20_normalize/_terms";

        if (!is_file($in)) {
            $io->error("Input JSONL not found: $in");
            return Command::FAILURE;
        }
        if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
            $io->error("Failed to create output dir: $outDir");
            return Command::FAILURE;
        }

        // Identify term-coded fields from properties (target names)
        $termFields = $this->parser->termFieldsFromProperties((array)($table->properties ?? []));

        if ($termFields === []) {
            $io->warning("No term/list fields found in $pixieCode.$core properties.");
            return Command::SUCCESS;
        }

        // Map: targetField => sourceField based on rules (e.g. "/cultura/" => "cul")
        $targetToSource = $this->invertRules((array)($table->rules ?? []));

        // Track terms: [set][code] => label + count
        $terms = [];

        $io->title("Extract terms: $pixieCode core=$core");
        $io->writeln("Input:  $in");
        $io->writeln("Output: $outDir");

        $fh = fopen($in, 'rb');
        if (!$fh) {
            $io->error("Unable to read $in");
            return Command::FAILURE;
        }

        $i = 0;
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $i++;
            if ($limit > 0 && $i > $limit) {
                break;
            }

            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }

            foreach ($termFields as $targetField => $def) {
                $setId = $def['set'];
                $sourceField = $targetToSource[$targetField] ?? $targetField;

                if (!array_key_exists($sourceField, $row)) {
                    continue;
                }

                $val = $row[$sourceField];

                // Normalize to list of labels
                $labels = $this->labelsFromValue($val);
                foreach ($labels as $label) {
                    $label = trim($label);
                    if ($label === '') {
                        continue;
                    }

                    $code = $this->codes->codeFromLabel($label);
                    if ($code === '') {
                        continue;
                    }

                    $terms[$setId] ??= [];
                    if (!isset($terms[$setId][$code])) {
                        $terms[$setId][$code] = ['label' => $label, 'count' => 0];
                    }
                    $terms[$setId][$code]['count']++;
                }
            }
        }

        fclose($fh);

        if ($terms === []) {
            $io->warning('No terms extracted.');
            return Command::SUCCESS;
        }

        // Write per set
        foreach ($terms as $setId => $map) {
            $path = rtrim($outDir, '/') . "/$setId.jsonl";

            if (is_file($path) && !$force) {
                $io->writeln("<comment>Skipping (exists): $path</comment>");
                continue;
            }

            $out = fopen($path, 'wb');
            if (!$out) {
                $io->error("Unable to write $path");
                continue;
            }

            // stable order
            ksort($map);

            foreach ($map as $code => $info) {
                $rec = [
                    'set' => $setId,
                    'code' => $code,
                    'label' => $info['label'],
                    'count' => $info['count'],
                ];
                fwrite($out, json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            }

            fclose($out);
            $io->writeln(sprintf('Wrote %s (%d terms)', $path, count($map)));
        }

        $io->success('Done.');
        return Command::SUCCESS;
    }

    /**
     * Invert rules like ["/cultura/" => "cul"] into ["cul" => "cultura"].
     * @param array<string,string> $rules
     * @return array<string,string>
     */
    private function invertRules(array $rules): array
    {
        $out = [];
        foreach ($rules as $src => $dst) {
            $srcKey = trim((string)$src, '/');
            $dstKey = trim((string)$dst);
            if ($srcKey !== '' && $dstKey !== '') {
                $out[$dstKey] = $srcKey;
            }
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    private function labelsFromValue(mixed $val): array
    {
        if (is_string($val)) {
            return [$val];
        }
        if (is_array($val)) {
            $out = [];
            foreach ($val as $v) {
                if (is_string($v)) {
                    $out[] = $v;
                }
            }
            return $out;
        }
        return [];
    }
}
