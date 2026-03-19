<?php

namespace Survos\PixieBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\CoreBundle\Service\SurvosUtils;
use Survos\PixieBundle\Entity\OriginalImage;
use Survos\PixieBundle\Model\Item;
use Survos\PixieBundle\Repository\OriginalImageRepository;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\StorageBox;
use Survos\SaisBundle\Model\AccountSetup;
use Survos\SaisBundle\Model\ProcessPayload;
use Survos\SaisBundle\Service\SaisClientService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand('pixie:media', 'dispatch to sais the original images')]
final class PixieMediaCommand extends Command
{


    // we are using the digmus translation dir since most projects are there.

    public function __construct(
        private PropertyAccessorInterface    $accessor,
        private readonly LoggerInterface     $logger,
        private EventDispatcherInterface     $eventDispatcher,
        private PixieService                 $pixieService,
//        private SaisClientService            $saisClientService,
        private readonly HttpClientInterface $httpClient,
        private readonly MessageBusInterface $messageBus,
    )
    {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle                                                $io,
        #[Argument(description: 'config code')] ?string             $configCode,
        #[Option(description: 'dispatch resize requests')] ?bool    $dispatch = null,
        #[Option(description: 'setup account (@todo: auto)')] ?bool $setup = null,
//        #[Option(description: 'populate the image keys with the iKv')] ?bool $merge = false,
//        #[Option(description: 'sync images from sais')] ?bool $sync = false,
//        #[Option(description: 'index when finished')] bool                   $index = false,
        #[Option()] int                                             $limit = 50,
        #[Option()] int                                             $batch = 10,
    ): int
    {
        $tableName = 'obj'; // could have images elsewhere.
        $configCode ??= getenv('PIXIE_CODE');

        $ctx = $this->pixieService->getReference($configCode);
        $config = $ctx->config;
        $repo = $ctx->repo(OriginalImage::class);
        $actualCount = $repo->count();
//        dd($actualCount);

        // if we have an approx, use it.  otherwise, actual count
        $approx = $config->getSource()->approx_image_count;
//        if (!$approx) {
//            $approx = $repo->count();
//            $io->error("Missing source|approx_image_count in config.  currently "
//                . $approx);
//            return Command::FAILURE;
//        }

        if ($setup) {
            // setup an account on sais with an approx for path creation
            $results = $this->saisClientService->accountSetup(new AccountSetup(
                $configCode,
                $approx?: $actualCount,
                mediaCallbackUrl: null
            ));
        }

        $dispatchCache = [];

            // dispatch to sais
            // @todo: dont dispatch if finished.
            $count = $limit ? min($limit, $actualCount) : $actualCount;
            $io->title(sprintf("Dispatching $count images %s::%s ",
                $this->saisClientService->getProxyUrl(),
                $this->saisClientService->getApiEndpoint()
            ));

            $progressBar = SurvosUtils::createProgressBar($io, $approx);
            $images = [];
            // we should dispatch a request for an API key, and set the callbacks and approx values
            $qb = $repo->createQueryBuilder('o')
//                ->orderBy('o.createdAt', 'DESC')
            ;
            // @todo: filter by status
            /**
             * @var  $idx
             * @var OriginalImage $image
             */
        $images = [];
        $imageCache = [];

        $processed = 0;                // exact # of items we've seen
        $batchSize = $batch;           // just to be explicit

        foreach ($progressBar->iterate($qb->getQuery()->toIterable()) as $image) {
            // Build request payload item
            $images[] = [
                'url' => $image->getUrl(),
                'context' => [], // add whatever SAIS needs here
            ];

            // Cache by code so we can map results back
            $imageCache[$image->getCode()] = $image;

            $processed++;

            // If we reached the limit, or we filled a batch, dispatch now
            $shouldDispatch =
                $dispatch && (
                    \count($images) >= $batchSize ||
                    ($limit !== null && $processed >= $limit)
                );

            if ($shouldDispatch) {
                $results = $this->saisClientService->dispatchProcess(new ProcessPayload(
                    $configCode,
                    $images
                ));

                $this->logger->info(\count($results) . ' images dispatched');
                $table = new Table($io);


                foreach ($results as $result) {
                    $imageCode = $result['code'] ?? null;
                    if (!$imageCode || !isset($imageCache[$imageCode])) {
                        $this->logger->warning('Result without matching imageCode', ['result' => $result]);
                        continue;
                    }

                    $img = $imageCache[$imageCode];

                    if (!empty($result['size'])) {
                        $img->size = $result['size'];
                    }
                    if (!empty($result['resized']) && \is_array($result['resized'])) {
                        $img->resized = $result['resized'];
                    }

                    // debug/log as needed
//                    if (!empty($result['resized']) && \is_array($result['resized'])) {
//                        dump($img->size, implode('|', array_keys($result['resized'])));
//                    }
                    $this->logger->info(sprintf(
                        '%s %s %s %s %s',
                        $result['code'] ?? '',
                        $result['originalUrl'] ?? '',
                        $result['size'] ?? '',
                        join('|', $result['resized']??[]),
                        $result['rootCode'] ?? ''
                    ));
                    $result['resized'] =
                    $table->addRow([
                        $result['code'],
                        $result['resized']['small']??null
                    ]);
//                    dump($img->resized);
                }
                $table->render();

                // persist & reset batch buffers
                $ctx->flush();
                // If you need to free memory and don't need managed entities anymore:
                // $ctx->clear();
                $images = [];
                $imageCache = [];
            }

            // If we hit the limit, stop after flushing what we had
            if ($limit !== null && $processed >= $limit) {
                break;
            }
        }

// after the loop, if dispatch disabled OR leftovers remain, handle tail
        if ($dispatch && \count($images) > 0) {
            $results = $this->saisClientService->dispatchProcess(new ProcessPayload(
                $configCode,
                $images
            ));
            $this->logger->info(\count($results) . ' images dispatched (tail)');

            foreach ($results as $result) {
                $imageCode = $result['code'] ?? null;
                if (!$imageCode || !isset($imageCache[$imageCode])) {
                    $this->logger->warning('Tail result without matching imageCode', ['result' => $result]);
                    continue;
                }
                $img = $imageCache[$imageCode];
                if (!empty($result['size'])) {
                    $img->size = $result['size'];
                }
                if (!empty($result['resized']) && \is_array($result['resized'])) {
                    $img->resized = $result['resized'];
                }
            }
            $ctx->flush();
        }

        $progressBar->finish();

// final safety flush (no-op if already flushed)
        $ctx->flush();

        //
        $io->writeln("\n\nfinished, now run pixie:merge --images --sync");
        return Command::SUCCESS;

    }

    private function mergeImageData(Item $item, StorageBox $iKv): array
    {
//        $images = [
//            [
//                'code' => 'abc',
//                'thumb'=> '...',
//                'orig'=> '...'
//        ];
        $images = [];
        foreach ($item->imageCodes() ?? [] as $key) {
            $imageData = ['code' => $key];
            foreach ($iKv->iterate('resized', where: [
                'imageKey' => $key,
            ], order: [
                'size' => 'asc',
            ]) as $row) {
                $imageData[$row->size()] = $row->url();
//                $imagesBySize[$row->size()][]=
//                    [
////                    'caption' => '??',
//                    'code'=>$key,
//                    'url' => $row->url()
//                ];
            }
            $images[] = $imageData;
        }
//        if (count($images)) {
//            dd($images);
//        }
        return $images;
    }


}
