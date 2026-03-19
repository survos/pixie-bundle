<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Survos\DataBundle\Repository\DatasetInfoRepository;
use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\Inst;
use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Service\PixieConfigRegistry;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Survos\PixieBundle\Entity\Term;
use Survos\PixieBundle\Entity\TermSet;

#[Route('/pixie')]
final class PixieBrowseController extends AbstractController
{
    public function __construct(
        private readonly PixieConfigRegistry $pixieConfigRegistry,
//        private EntityManagerInterface $em,
        private DatasetInfoRepository $datasetInfoRepository,
        private readonly PixieService $pixieService) {}

    #[Route('', name: 'pixie_browse_configs', methods: ['GET'])]
    #[Template('@SurvosPixie/pixie/index.html.twig')]
    public function index(#[MapQueryParameter] int $limit = 50): array
    {
        // Try DatasetInfo first (populated by data:scan-datasets)
//            $em = $this->pixieService->getSharedEntityManager();
                $datasetInfos = $this->datasetInfoRepository
                    ->createQueryBuilder('d')
                    ->orderBy('d.pixieDbPath', 'DESC')
                    ->addOrderBy('d.aggregator')
                    ->addOrderBy('d.datasetKey')
                    ->setMaxResults($limit)
                    ->getQuery()->getResult();

        return [
            'configs'      => $this->pixieConfigRegistry->all(),
            'datasetInfos' => $datasetInfos,
            'limit'        => $limit,
            'dbDir'        => $this->pixieService->getPixieDbDir(),
        ];
    }

    #[Route('/{pixieCode}', name: 'pixie_dashboard', options: ['expose' => true], methods: ['GET'])]
    #[Template('@SurvosPixie/pixie/dashboard.html.twig')]
    public function dashboard(string $pixieCode): array
    {
        $ctx = $this->pixieService->getReference($pixieCode);
        $em  = $ctx->em;

        $pixieFile = $this->pixieService->getPixieFilename($pixieCode);
        $dbSize = is_file($pixieFile) ? (int) filesize($pixieFile) : 0;

        /** @var Owner|null $owner */
        $owner = $em->getRepository(Inst::class)->find($pixieCode);

        // DB cores and counts
        $cores = $em->getRepository(Core::class)->findAll();
        $rowsByCore = [];
        foreach ($cores as $core) {
            $count = $em->getRepository(Row::class)->count(['core' => $core]);

            // config “schema” for this core
            $table = $ctx->config->getTable($core->code);

            $propertyCodes = [];
            if ($table) {
                foreach ($table->getProperties() as $p) {
                    $propertyCodes[] = (string)$p;
                }
            }

            $rowsByCore[] = [
                'core' => $core,
                'count' => $count,
                'table' => $table,
                'translatable' => $table?->getTranslatable() ?? [],
                'propertyCodes' => $propertyCodes,
            ];
        }

        // -----------------------------
        // TERMSETS + COUNTS (treat like cores; all entities via $ctx->em)
        // -----------------------------
        $termSets = $em->getRepository(TermSet::class)->findAll();

        // Grouped query to avoid N+1 counts. Assumes Term has ManyToOne "termSet".
        $termCountsByTermSetId = [];
        try {
            $rows = $em->createQueryBuilder()
                ->select('IDENTITY(t.termSet) AS termSetId, COUNT(t.id) AS termCount')
                ->from(Term::class, 't')
                ->groupBy('termSetId')
                ->getQuery()
                ->getArrayResult();

            foreach ($rows as $r) {
                $termCountsByTermSetId[(string) $r['termSetId']] = (int) $r['termCount'];
            }
        } catch (\Throwable) {
            // If mapping differs, we still support the per-termset count fallback below.
            $termCountsByTermSetId = [];
        }

        $termSetsData = [];
        foreach ($termSets as $termSet) {
            // Use whatever your TermSet identifier is; many of your Pixie entities use public id.
            $tsId = (string) ($termSet->id ?? ($termSet->getId() ?? ''));

            $termCount = $termCountsByTermSetId[$tsId]
                ?? $em->getRepository(Term::class)->count(['termSet' => $termSet]);

            // Top terms: conservative ordering. If you have a "count"/"weight" field, swap orderBy.
            $topTerms = $em->getRepository(Term::class)->findBy(
                ['termSet' => $termSet],
                ['id' => 'DESC'],
                10
            );

            $termSetsData[] = [
                'termSet' => $termSet,
                'count' => $termCount,
                'topTerms' => $topTerms,
            ];
        }


        // Config-only cores (defined in YAML but not yet ingested)
        $definedCores = [];
        foreach ($ctx->config->tables as $coreCode => $table) {
            $definedCores[$coreCode] = $table;
        }
        $config = $ctx->config;

        $babel = $config->babel;
        return [

            'pixieConfig' => $config,                     // Survos\PixieBundle\Model\Config
            'sourceLocale' => $babel->source , // string
            'targetLocales' => $config->getTargetLocales(), // list<string>
            'termSetsData' => $termSetsData,

            'pixieCode' => $pixieCode,
            'conf' => $ctx->config,
            'owner' => $owner,
            'dbFile' => $pixieFile,
            'dbSize' => $dbSize,
            'rowsByCore' => $rowsByCore,
            'definedCores' => $definedCores,
        ];
    }

    #[Route('/{pixieCode}/core/{coreCode}', name: 'pixie_core_browse', methods: ['GET'])]
    #[Template('@SurvosPixie/pixie/browse_core.html.twig')]
    public function browseCore(
        string $pixieCode,
        string $coreCode,
        #[MapQueryParameter] int $limit = 50,
        #[MapQueryParameter] int $offset = 0,
    ): array {
        $ctx = $this->pixieService->getReference($pixieCode);
        $em  = $ctx->em;

        $core = $em->getRepository(Core::class)->find($coreCode)
            ?? throw $this->createNotFoundException("Unknown core $coreCode");

        $rows = $em->getRepository(Row::class)->findBy(
            ['core' => $core],
            ['id' => 'ASC'],
            limit: max(1, $limit),
        );

        $count = $em->getRepository(Row::class)->count(['core' => $core]);
        $table = $ctx->config->getTable($core->code);

        return [
            'pixieCode' => $pixieCode,
            'conf' => $ctx->config,
            'source' => $ctx->config->source,
            'core' => $core,
            'table' => $table,
            'rows' => $rows,
            'count' => $count,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    #[Route('/{pixieCode}/termset/{termSetId}', name: 'pixie_termset_browse', methods: ['GET'])]
    #[Template('@SurvosPixie/pixie/browse_termset.html.twig')]
    public function browseTermSet(
        string $pixieCode,
        string $termSetId,
        #[MapQueryParameter] int $limit = 50,
        #[MapQueryParameter] int $offset = 0,
    ): array {
        $ctx = $this->pixieService->getReference($pixieCode);
        $em  = $ctx->em;

        /** @var TermSet|null $termSet */
        $termSet = $em->getRepository(TermSet::class)->find($termSetId)
            ?? throw $this->createNotFoundException("Unknown TermSet $termSetId");

        $terms = $em->getRepository(Term::class)->findBy(
            ['termSet' => $termSet],
            ['id' => 'ASC'],
            limit: max(1, $limit),
            offset: max(0, $offset),
        );

        $count = $em->getRepository(Term::class)->count(['termSet' => $termSet]);

        return [
            'pixieCode' => $pixieCode,
            'conf' => $ctx->config,
            'termSet' => $termSet,
            'terms' => $terms,
            'count' => $count,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    #[Route('/{pixieCode}/row/{rowId}', name: 'pixie_row_show', requirements: ['rowId' => '.+'], methods: ['GET'])]
    #[Template('@SurvosPixie/pixie/show_row.html.twig')]
    public function showRow(string $pixieCode, string $rowId): array
    {
        $ctx = $this->pixieService->getReference($pixieCode);
        $em  = $ctx->em;

        /** @var Row|null $row */
        $row = $em->getRepository(Row::class)->find($rowId);
        if (!$row) {
            throw $this->createNotFoundException("Row not found: $rowId");
        }

        $coreCode = $row->core->code;
        $table = $ctx->config->getTable($coreCode);

        $propertyCodes = [];
        if ($table) {
            foreach ($table->getProperties() as $p) {
                $propertyCodes[] = $p->getCode();
            }
        }

        return [
            'pixieCode' => $pixieCode,
            'conf' => $ctx->config,
            'row' => $row,
            'coreCode' => $coreCode,
            'table' => $table,
            'propertyCodes' => $propertyCodes,
            'translatable' => $table?->getTranslatable() ?? [],
            'strCodeMap' => method_exists($row, 'getStrCodeMap') ? $row->getStrCodeMap() : [],
        ];
    }

    #[Route('/_search', name: 'pixie_config_search')]
//    #[Template()]
    public function search_pixies(
        #[MapQueryParameter] int $limit = 50,
        #[MapQueryParameter] string $q = '',
        #[MapQueryParameter] string $pixieCode = ''
    ): array|Response
    {
        $configs = $this->pixieService->getConfigFiles($q, limit: $limit, pixieCode: $pixieCode);
        // cache candidate!
        $tables = [];
        foreach ($configs as $pixieCode => $config) {
            assert($config->getCode(), $pixieCode);
            $pixieContext = $this->pixieService->getStorageBox($pixieCode);
            foreach ($pixieContext->config->getTables() as $tableName => $table) {
                // how many items in the table
                $tables[$pixieCode][$tableName]['count'] = -4; //  $kv->count($tableName);
                // the key indexes
                $indexCounts = []; // $this->getCounts($kv, $tableName, $limit);
                $tables[$pixieCode][$tableName]['indexes'] = $indexCounts;
//                foreach ($kv->getIndexes($tableName) as $indexName) {
//                    $tables[$pixieCode][$tableName]['indexes'][$indexName] = $kv->getCounts($indexName, $tableName);
//                }
            }
        }

        return $this->render('@SurvosPixie/pixie/_search_results.html.twig', [
                'configs' => $configs,
                'dir' => $this->pixieService->getConfigDir(),
                'tables' => $tables,

            ]
        );

    }

}
