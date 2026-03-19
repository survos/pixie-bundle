<?php

namespace Survos\PixieBundle\Command;

use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Message\PixieTransitionMessage;
use Survos\PixieBundle\Service\LocaleContext;
use Survos\PixieBundle\Service\PixieImportService;
use Survos\PixieBundle\Service\PixieService;
use Survos\StateBundle\Service\WorkflowHelperService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Workflow\Transition;

#[AsCommand('pixie:iterate', 'Iterative over a pixie database, sending events"')]
final class IterateCommand extends Command
{
    private bool $initialized = false; // so the event listener can be called from outside the command
    private ProgressBar $progressBar;

    public function __construct(
        private LoggerInterface           $logger,
        private readonly PixieService     $pixieService,
        private LocaleContext             $localeContext,
        private ?WorkflowHelperService    $workflowHelperService = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
    )
    {

        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle                                           $io,
        #[Argument(description: 'config code')] string         $pixieCode,
        #[Argument(description: 'table name')] string          $tableName,
//        #[Autowire('%env(DEFAULT_TRANSPORT)%')] ?string        $defaultTransport = null,
        #[Option(description: 'use image database')] bool      $image = false,
        #[Option(description: 'workflow transition')] ?string  $transition = null,
        // marking CAN be null, which is why we should set it when inserting
        #[Option(description: 'workflow marking')] ?string     $marking = null,
        #[Option(description: 'message transport')] ?string    $transport = null,
        #[Option(description: 'tags (for listeners)')] ?string $tags = null,
        #[Option] int                                          $limit = 0,
        #[Option] string                                       $dump = '',
        #[Option('switch to locale')] string                   $locale = 'en'
    ): int
    {
//        $transport ??= $defaultTransport;

        if ($locale) {
            $this->localeContext->set($locale);
            $io->note('Locale set to ' . $this->localeContext->get());
        }

        $ctx = $this->pixieService->getReference($pixieCode);
        $config = $ctx->config;

        $rowRepo = $ctx->repo(Row::class);
        $rows = $rowRepo->findBy([], [], 10);
        foreach ($rows as $row) {
            dd($row);
        }


        $table = $config->getTables()[$tableName];
        $workflow = null;
        if ($workflowName = $table->getWorkflow()) {
            $workflow = $this->workflowHelperService->getWorkflowByCode($table->getWorkflow());
            if ($marking) {
                $places = array_values($workflow->getDefinition()->getPlaces());
                assert(in_array($marking, $places), "invalid marking:\n\n$marking: use\n\n" . implode("\n", $places));
            }
            if ($transition) {
                $transitions = array_unique(array_map(fn(Transition $transition) => $transition->getName(), $workflow->getDefinition()->getTransitions()));
                assert(in_array($transition, $transitions), "invalid transition:\n\n$transition: use\n\n" . implode("\n", $transitions));
            }

            /* eh, nice idea, maybe someday
//            dd($workflow, $workflowName);
            if ($transition) {
                if (!constant($workflow::class . "::$transition")) {
                    $io->error("Invalid transition $transition in workflow $workflowName " . $workflow::class);
                }
            }
            */
        }
        {
            $io->title($tableName);
            $where = [];
            if ($marking) {
                $where = ['marking' => $marking];
            }
            $count = $kv->count(where: $where);
            if (!$count) {
                $io->warning("No items found for " . json_encode($where));
                return Command::SUCCESS;
            }

            $progressBar = new ProgressBar($io, $count);
            $idx = 0;
            $stamps = [];
            if ($transport) {
                $stamps[] = new TransportNamesStamp($transport);
            }
            if ($dump) {
                $headers = explode(',', $dump);
                if (!in_array('key', $headers)) {
                    $headers[] = 'key';
                }
                $table = new Table($io);
                $table->setHeaders($headers);
                $table->render();
            }

            foreach ($kv->iterate(where: $where) as $key => $item) {
                $idx++;
                if ($dump) {
                    $values = array_map(fn($key) => substr((string)$item->{$key}(), 0, 40), $headers);
                    $table->addRow($values);
                    $table->render();
                }

                // since we have the workflow and transition, we can do a "can" here.
                if ($workflow && $transition) {
                    if (!$workflow->can($item, $transition)) {
                        $this->logger->info("$item cannot transition from {$item->getMarking()} to $transition");
                        continue;
                    } else {
                        // if there's a workflow and a transition, dispatch a transition message, otherwise a simple row event
                        try {
                            $envelope = $this->bus->dispatch($message = new PixieTransitionMessage(
                                $pixieCode,
                                $key,
                                $tableName,
                                $transition,
                                $workflowName,
                                $transport
                            ), $stamps);
                        } catch (\Exception $e) {
                            $this->logger->warning($e->getMessage());
                            dd($message);
                        }
                    }
                } else {
                    // no workflow, so dispatch the row event and let the listeners handle it.
                    $this->eventDispatcher->dispatch(
                        $rowEvent = new RowEvent(
                        // this looks more like an item!!
                            $pixieCode,
                            $tableName,
                            (array)$item->getData(),
                            $item,
                            $key,
                            $idx,
                            $count,
                            type: RowEvent::LOAD,
                            action: self::class,
                            storageBox: $kv,
                            config: $config,
                            context: [
//                                'storageBox' => $kv,
                                'tags' => explode(",", (string)$tags),
                                'transition' => $transition,
                                'transport' => $transport
                            ])
                    );

//                    dd($rowEvent);
                }


                // if it's an event that changes the values, like a cleanup, we need to update the row.
                // if it's just dispatching an event, then we don't.
                // @todo: update
                if ($limit && $idx >= ($limit - 1)) {
                    break;
                }
                $progressBar->advance();
            }
            $progressBar->finish();
        }

        // final dispatch, to process
        $this->eventDispatcher->dispatch(
            $rowEvent = new RowEvent(
                $pixieCode,
                $tableName,
                storageBox: $kv,
                type: RowEvent::POST_LOAD,
                action: self::class,
                context: [
                    'tags' => $tags ? explode(",", $tags) : [],
                    'transition' => $transition,
                    'transport' => $transport
                ])
        );

        $io->success($this->getName() . ' success ' . $kv->getFilename());
        return Command::SUCCESS;
    }

}
