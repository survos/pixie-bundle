<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Controller;

use Survos\PixieBundle\Entity\Term;
use Survos\PixieBundle\Entity\TermSet;
use Survos\PixieBundle\Service\LocaleContext;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PixieTermsController extends AbstractController
{
    public function __construct(
        private readonly PixieService $pixie,
        private readonly LocaleContext $localeContext,
    ) {
    }

    #[Route('/pixie/{pixieCode}/terms/{setId?}', name: 'pixie_terms', methods: ['GET'])]
    public function __invoke(string $pixieCode, ?string $setId = null): Response
    {
        $ctx = $this->pixie->getReference($pixieCode);

        /** @var list<TermSet> $sets */
        $sets = $ctx->repo(TermSet::class)->findBy([], ['id' => 'ASC']);

        if ($sets === []) {
            return $this->render('pixie/terms.html.twig', [
                'pixieCode' => $pixieCode,
                'setId' => null,
                'sets' => [],
                'terms' => [],
                'columns' => [],
            ]);
        }

        $setId ??= $sets[0]->id;

        /** @var list<Term> $terms */
        $terms = $ctx->repo(Term::class)->findBy(['set' => $setId], ['code' => 'ASC']);

        // Optionally ensure locale context; terms resolve via Babel resolver if you wire postLoad similarly to Row
        $locale = $this->localeContext->getCurrent() ?? $this->localeContext->getDefault();
        $this->localeContext->run($locale, fn() => null);

        $columns = ['code', 'label', 'count'];

        return $this->render('pixie/terms.html.twig', [
            'pixieCode' => $pixieCode,
            'setId' => $setId,
            'sets' => $sets,
            'terms' => $terms,
            'columns' => $columns,
        ]);
    }
}
