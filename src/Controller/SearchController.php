<?php

namespace Survos\PixieBundle\Controller;

// this is more MeiliController, it depends on the MeiliService.  Wrapper for meili index

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\IriConverterInterface;
use App\Entity\MeiliItem;
use App\Entity\Inst;
use App\Form\LabelFormType;
use Survos\ApiGrid\Model\Column;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\InspectionBundle\Services\InspectionService;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/pixie-search/{pixieCode}/{tableName}')]
class SearchController extends AbstractController
{
    public function __construct(
        private readonly PixieService $pixieService,
        private readonly ?IriConverterInterface $iriConverter=null,
        private readonly ?MeiliService $meiliService=null

//        private ?AuthorizationCheckerInterface $authorizationChecker=null
    )
    {
    }

    private function getMeiliService(): MeiliService
    {
        if (!$this->meiliService) {
            throw new \Exception("install meilisearch to use this controller");
        }
        return $this->meiliService;

    }

    /**
     * this handles both pixie properties and the meili index,
     *
     * @param string $indexName
     * @param Request $request
     * @param string $_format
     * @return Response
     */
    #[Route(path: '/stats/{indexName}.{_format}', name: 'pixie_meili_stats', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function stats(
        string  $indexName,
        Request $request,
        string $_format='html'
    ): Response
    {
        $index = $this->getMeiliService()->getIndex($indexName);
        $stats = $index->stats();
        // idea: meiliStats as a component?
        $data = [
            'indexName' => $indexName,
            'settings' => $index->getSettings(),
            'stats' => $stats
        ];
        return $_format == 'json'
            ? $this->json($data)
            : $this->render('@SurvosApiGrid/stats.html.twig', $data);
    }


    #[Route('/grid', name: 'pixie_meili_browse', options: ['expose' => true])]
    #[Template('@SurvosPixie/pixie/grid.html.twig')]
    public function meili(
        string $pixieCode,
        ?string $subCode,
        string $tableName,
    ): array
    {
        if (!$this->meiliService) {
            throw new \RuntimeException('Meili service not available, run composer req ...?');
        }
        $indexName = $this->meiliService->getPrefixedIndexName(PixieService::getMeiliIndexName($pixieCode, $subCode, $tableName));
        $operation = (new GetCollection())->withClass(MeiliItem::class);
        // pass in an object if all the parameters are available in the object,
        //  or pass in the class and the uri_variables.
        $apiUrl = $this->iriConverter->getIriFromResource(MeiliItem::class, operation:$operation, context: [
            'uri_variables' => ['indexName' => $indexName, 'tableName' => $tableName, 'pixieCode' => $pixieCode],
        ]);
        $config = $this->pixieService->selectConfig($pixieCode);
        $table = $config->getTable($tableName);
        assert($table, "Missing $tableName in $pixieCode");
        $footerColumns  = $attrColumns = $textColumns = [];
        $gridColumns = [
            new Column(
                name: 'chevron',
                title: '>',
//                sortable: true,
                browsable: false
            ),
            new Column(
                name: 'pixie_key',
                condition: $this->isGranted('ROLE_ADMIN'),
                title: 'id',
//                sortable: true,
                browsable: false
            )];
        if ($table->isHasImages()) {
            $gridColumns[] =
                new Column(
                    name: 'thumbnail',
                    browsable: false
                );
        }
        $gridColumns = array_merge($gridColumns, [
            new Column(
                name: 'tombstone',
                className: 'tombstone-heading',
                browsable: false
            ),
            new Column(
                name: 'attrs',
                className: 'tombstone-heading',
                browsable: false
            ),
        ]);

//        foreach ($table->getTranslatable() as $field) {
//            $gridColumns[] = new Column(name: $field, browsable: false,
//                className: 'custom-heading',
//                searchable: true, order: 7); // searchable should highlight
//        }
        // could also go through indexes
        foreach ($table->getProperties() as $property) {
            if ($property->getSetting('g') == 'ignored') {
                continue;
            }
            if ($property->getIndex() === 'PRIMARY') {
                continue;
            }

            $browsable = $property->getIndex()=='INDEX'
                || $property->isRelation()
                || $property->getListTableName();

            $column = new Column(
                name: $property->getCode(),
                browsable: $browsable,
                sortable: $property->getIndex()=='INDEX',
            );
            if ($browsable) {
                $column->order=0; // disable
            }
            if ($property->isRelation()) {
//                dd($property, $column);
            }
            if ($condition = $property->getSetting('security')) {
//                $column->condition = $this->security->isGranted($condition); // sprintf("isGranted('%s')", $condition);
                $column->condition = $this->isGranted($condition); // sprintf("isGranted('%s')", $condition);
            }
            if ($title = $property->getSetting('title')) {
                $column->title = $title;
            }

            if (in_array($property->getCode(), $table->getTranslatable())) {
                $textColumns[] = $column;
                // hmm
            } elseif ($property->getSetting('footer')) {
                $footerColumns[] = $column;
            } else {
                if ($column->sortable || $column->browsable) {
                    $attrColumns[] = $column;
                }
            }

            // since attr, text and footers are now displayed in tombstone or attrs, we can hide them from the grid.

        }
//        https://mus.wip/api/meili/belvedere/object/mus_pixie_belvedere
//        return $this->render('@SurvosPixie/pixie/grid.html.twig',
        // return an array so it can be called outside of PixieController, e.g. OwnerController
        return
            [
                'indexName' => $indexName,
                'apiUrl' => $apiUrl,
                'pixieCode' => $pixieCode,
                'columns' => $gridColumns,
                'attrs' => $attrColumns,
                'text_properties' => $textColumns,
                'tombstone_footer' => $footerColumns,
                'class' => MeiliItem::class,
                'tableName' => $tableName,
                'filter' => [], // only if we are able to merge the meili indexes ['table' => $tableName]
            ];
    }

    #[Route('/{ownerId}/labels', name: 'owner_labels', options: ['expose' => true])]
    public function labels(Request $request,
                           string $tableName,
                           string $pixieCode,
                           Inst $inst,
                           #[MapQueryParameter] ?string $q=null,
                           #[MapQueryParameter] array $ff=[], // filters
    ): Response
    {
        // @todo: https://gist.github.com/armadsen/5084458

        $data = [
            'q' => $q,
            'ff' => json_encode($ff),
            'elements' => ['qr', 'description'],
            'number_of_columns' => 3,
            'number_of_rows' => 8,
            'row_height' => 10,
            'row_width' => 40,
            'left_margin' => 12,
            'column_spacing' => 6,
        ];
        // https://github.com/amattu2/avery-fpdf-labels/blob/master/example.php
        $form = $this->createForm(LabelFormType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            // @todo: the search!
//            if ($data['slideshow'])
            {
//                $apiUrl =
                return $this->redirectToRoute('owner_slideshow',
                    $data + [
                        'pixieCode' => $pixieCode,
                        'tableName' => $tableName,
                        'ownerId' => $owner->getId()]);

            }
            return $this->redirectToRoute('owner_label_report',
                $data + [
                    'pixieCode' => $pixieCode,
                    'tableName' => $tableName,
                    'ownerId' => $owner->getId()]);
        }

        return $this->render('owner/label-form.html.twig', get_defined_vars() + [
                'form' => $form->createView(),
            ]);
    }

    #[Route('/{ownerId}/slideshow', name: 'owner_slideshow')]
    public function slideshow(Request $request,
                                 string $tableName,
                                 string $pixieCode,
                                 Inst $inst): Response
    {
        // pixieCode should allow slashes for subCode, this won't work with md
        $pixieCode = $owner->getPixieCode();
        // @todo: get filtered data
        $kv = $this->pixieService->getStorageBox($owner->getPixieCode());
        $properties = $kv->getTable($tableName)->getProperties();
        $items = $kv->iterate($tableName, max: 30);
        // hack to not repeat getting this, but really it's apiUrl we want
        $meiliData = $this->meili($pixieCode, null, tableName: $tableName);
        return $this->render('owner/slideshow.html.twig', [
            'apiUrl' => $meiliData['apiUrl'],
            '_locale' => $request->getLocale(),
            'properties' => $properties,
            'tableName' => $tableName,
//            'items' => $items,
            'owner' => $owner
        ]);

    }

    #[Route('/{ownerId}/report', name: 'owner_label_report')]
    public function label_report(Request $request,
                                 string $tableName,
                                 string $pixieCode,
                                 Inst $inst): Response
    {
        // pixieCode should allow slashes for subCode, this won't work with md
        $pixieCode = $owner->getPixieCode();
        // @todo: get filtered data
        $kv = $this->pixieService->getStorageBox($owner->getPixieCode());
        $properties = $kv->getTable($tableName)->getProperties();
        $items = $kv->iterate($tableName, max: 30);

        if (!$this->meiliService) {
            throw new \RuntimeException('Meili service not available, run composer req ...?');
        }

        $indexName = $this->meiliService->getPrefixedIndexName(PixieService::getMeiliIndexName($pixieCode, null, $tableName));
        $index = $this->meiliService->getIndex($indexName);
        $items = $index->search(null);

        $config = $this->pixieService->selectConfig($pixieCode);
        $table = $config->getTable($tableName);



        return $this->render('owner/labels.html.twig', [
            '_locale' => $request->getLocale(),
            'properties' => $properties,
            'tableName' => $tableName,
            'items' => $items->getHits(),
            'owner' => $owner
        ]);
    }

//    private function isGranted($attribute, $subject = null): bool
//    {
//        if (! $this->authorizationChecker) {
//            throw new \Exception("try composer require symfony/security-bundle to use this feature");
//        }
//        return $this->authorizationChecker->isGranted($attribute, $subject);
//    }

}
