<?php

declare(strict_types=1);

namespace Survos\PixieBundle\Menu;

use Survos\TablerBundle\Event\MenuEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class PixieBundleMenuSubscriber
{
    #[AsEventListener(event: MenuEvent::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        $menu = $event->getMenu();
        $submenu = $menu->addChild('Pixie');
        $submenu->addChild('Configurations', ['route' => 'pixie_browse_configs']);
    }
}
