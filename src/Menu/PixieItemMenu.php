<?php

namespace Survos\PixieBundle\Menu;

use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Service\MenuService;
use Survos\TablerBundle\Traits\KnpMenuHelperInterface;
use Survos\TablerBundle\Menu\MenuBuilderTrait;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class PixieItemMenu
{
    use MenuBuilderTrait;

    public function __construct(
        #[Autowire('%kernel.environment%')] protected string $env,
        private PixieService $pixieService,
        private ?MenuService $menuService=null
    ) {
    }

    #[AsEventListener(event: MenuEvent::PAGE)]
    #[AsEventListener(event: MenuEvent::SIDEBAR_MENU)]
    public function pixiePageMenu(MenuEvent $event): void
    {
        // there must be a pixie.  Messy, because this goes in app, need to add it to the config in pixie
        $menu = $event->getMenu();
        if (!$itemKey = $event->getOption('itemKey')) {
            return; // pixie  browse should be handled outside of this menu.
        }
        if (!$tableName = $event->getOption('tableName')) {
            return;
        }
        if (!$pixieCode = $event->getOption('pixieCode')) {
            return;
        }
        return;
        $kv = $this->pixieService->getStorageBox($pixieCode);

        $this->addHeading($menu, $itemKey,
            translationDomain: false);

        if ($item = $kv->get($itemKey, $tableName)) {
            $this->addHeading($menu, $item->getKey());
            $this->add($menu, 'pixie_show_record', $item->getRp());
            $this->add($menu, 'pixie_share_item', $item->getRp());
        }
    }

}
