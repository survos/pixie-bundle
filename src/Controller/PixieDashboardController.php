<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Controller;

use Survos\PixieBundle\Entity\Core;
use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/pixie')]
final class PixieDashboardController extends AbstractController
{
    public function __construct(private readonly PixieService $pixie) {}

    #[Route('/{pixieCode}', name: 'pixie_dashboard', options: ['expose' => true])]
    #[Template('@SurvosPixie/pixie/dashboard.html.twig')]
    public function dashboard(string $pixieCode): array
    {
        $ctx = $this->pixie->getReference($pixieCode);
        $em  = $ctx->em;

        // summary from config
        $conf   = $ctx->config;
        $pixieFile = $this->pixie->getPixieFilename($pixieCode);
        $dbSize = is_file($pixieFile) ? filesize($pixieFile) : 0;

        // cores & counts
        $cores = $em->getRepository(Core::class)->findAll();
        $rowsByCore = [];
        foreach ($cores as $core) {
            $count = $em->getRepository(Row::class)->count(['core' => $core]);
            $rowsByCore[] = ['core' => $core, 'count' => $count];
        }

        return [
            'pixieCode'   => $pixieCode,
            'config'        => $conf,
            'conf'        => $conf,
            'source'      => $conf->source,
            'dbFile'      => $pixieFile,
            'dbSize'      => $dbSize,
            'rowsByCore'  => $rowsByCore,
        ];
    }

    #[Route('/{pixieCode}/core/{coreCode}', name: 'pixie_core_browse')]
    #[Template('@SurvosPixie/pixie/browse_core.html.twig')]
    public function browseCore(string $pixieCode, string $coreCode): array
    {
        $ctx = $this->pixie->getReference($pixieCode);
        $em  = $ctx->em;

        $core = $em->getRepository(Core::class)->find($coreCode)
             ?? throw $this->createNotFoundException("Unknown core $coreCode");

        // list a few rows
        $rows = $em->getRepository(Row::class)->findBy(['core' => $core], ['id' => 'ASC'], 50);

        return [
            'pixieCode' => $pixieCode,
            'core'      => $core,
            'rows'      => $rows,
        ];
    }
}
