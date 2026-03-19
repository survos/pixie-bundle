<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Compiler;

use Survos\MeiliBundle\Service\MeiliService;
use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Service\PixieConfigRegistry;
use Survos\PixieBundle\Service\PixieMeiliSettingsFromConfig;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class PixieMeiliIndexPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(MeiliService::class)) {
            return;
        }

        if (!$container->hasDefinition(PixieConfigRegistry::class)) {
            return;
        }

        $def = $container->getDefinition(PixieConfigRegistry::class);
        $pixies = $def->getArgument('$pixiesConfig');

        if (!$pixies) {
            @trigger_error('[Survos/Pixie] No Pixie config found in container; Pixie Meili indexes will not be registered.', E_USER_NOTICE);
            return;
        }

        $indexEntities = $container->hasParameter('meili.index_entities') ? (array) $container->getParameter('meili.index_entities') : [];
        $indexSettings = $container->hasParameter('meili.index_settings') ? (array) $container->getParameter('meili.index_settings') : [];
        $indexNames    = $container->hasParameter('meili.index_names')    ? (array) $container->getParameter('meili.index_names')    : [];

        $builder = new PixieMeiliSettingsFromConfig();

        foreach ($pixies as $pixieCode => $cfg) {
            if (!is_string($pixieCode) || $pixieCode === '' || !is_array($cfg)) {
                continue;
            }

            // Locale policy (stored as metadata on the base key)
            $source = (string)(($cfg['babel']['source'] ?? $cfg['babel']['from'] ?? $cfg['sourceLocale'] ?? 'en') ?: 'en');
            $source = strtolower(trim($source)) ?: 'en';

            $targets = $cfg['babel']['targets'] ?? $cfg['babel']['to'] ?? [];
            $targets = is_array($targets) ? $targets : [];
            $targets = array_values(array_unique(array_filter(array_map(
                static fn($v) => strtolower(trim((string) $v)),
                $targets
            ), static fn($v) => $v !== '' && $v !== $source)));

            // BASE index key: just the pixie code (sanitized), NO px_ prefix
            $baseName = preg_replace('/[^a-zA-Z0-9_]/', '_', $pixieCode) ?: $pixieCode;

            // Collision guard: if something else already registered this base name, fail loudly
            if (isset($indexEntities[$baseName]) && $indexEntities[$baseName] !== Row::class) {
                throw new \RuntimeException(sprintf(
                    "Pixie base index name '%s' (pixie '%s') conflicts with existing index entity '%s'. Rename the pixie code or change index naming.",
                    $baseName,
                    $pixieCode,
                    (string) $indexEntities[$baseName]
                ));
            }

            // Build schema/facets once (locale-independent)
            $meili = $builder->buildForPixie($pixieCode, $cfg);

            // UI label should NOT include locale (same template for all languages)
            $ui = $meili['ui'] ?? ['icon' => 'Pixie', 'label' => $pixieCode];
            $ui['origin'] = $ui['origin'] ?? 'pixie';
            $ui['pixie']  = $ui['pixie']  ?? $pixieCode;

            $indexEntities[$baseName] = Row::class;

            $indexSettings[Row::class][$baseName] = [
                'schema'     => $meili['schema'],
                'primaryKey' => 'id',
                'persisted'  => [],
                'class'      => Row::class,
                'facets'     => $meili['facets'],
                'embedders'  => $meili['embedders'] ?? [],
                'autoIndex'  => false,

                'locales'    => [
                    'source'  => $source,
                    'targets' => $targets,
                ],

                // Template is locale-agnostic; start with pixieCode
                'template'   => $pixieCode,

                'ui'         => $ui,
            ];

            if (!in_array($baseName, $indexNames, true)) {
                $indexNames[] = $baseName;
            }
        }

        sort($indexNames);

        $container->setParameter('meili.index_names', $indexNames);
        $container->setParameter('meili.index_entities', $indexEntities);
        $container->setParameter('meili.index_settings', $indexSettings);

        // Do NOT set MeiliService args here; MeiliIndexPass merges and injects.
    }
}
