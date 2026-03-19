<?php

namespace Survos\PixieBundle\EventListener;

use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ControllerEvent;

final class PixieControllerEventListener
{
    public function __construct(
        private RequestStack $requestStack,
//        #[Target('pixieEntityManager')] private EntityManagerInterface $pixieEntityManager,
        private PixieService $pixieService,
        private LoggerInterface $logger,
    )
    {

    }
    #[AsEventListener(ControllerEvent::class)]
    public function onKernelController(ControllerEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $pixieCode =  $request?->attributes->get('pixieCode');
        if ($pixieCode) {
            // maybe...
//            $this->pixieService->selectConfig($pixieCode);
            $ctx = $this->pixieService->getReference($pixieCode); // iffy too
            $this->logger->warning("Setting pixie service to $pixieCode");
            // now we can also check permissions!
        }
    }
}
