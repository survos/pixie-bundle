<?php

declare(strict_types=1);

namespace Survos\PixieBundle\Menu;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PixieBundleMenuSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        // Only subscribe if tabler-bundle's MenuEvent exists
        if (!class_exists(\Survos\TablerBundle\Event\MenuEvent::class)) {
            return [];
        }

        return [
            \Survos\TablerBundle\Event\MenuEvent::NAVBAR_MENU => 'onNavbarMenu',
        ];
    }

    public function onNavbarMenu($event): void
    {
        $menu = $event->getMenu();

        // Add Pixie submenu with link to configs
        $submenu = $this->addSubmenu($menu, 'Pixie');
        $submenu->addChild('pixie_configs', [
            'route' => 'pixie_browse_configs',
            'label' => 'Configurations',
        ]);
    }

    private function addSubmenu($menu, string $label, ?string $icon = null): mixed
    {
        $submenu = $menu->addChild($label);
        if ($icon) {
            $submenu->setAttribute('icon', $icon);
        }
        return $submenu;
    }
}
