<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Schema;

use JetBrains\PhpStorm\Deprecated;
use Survos\PixieBundle\Entity\CoreDefinition;
use Survos\PixieBundle\Entity\FieldDefinition;
use Survos\PixieBundle\Model\Config;
use Survos\PixieBundle\Model\Property;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Service\SqlViewService;

#[Deprecated]
/**
 * Compiles survos_pixie YAML into CoreDefinition + FieldDefinition
 * in the *pixie database* (correct EM), not the default app EM.
 */
final class YamlSchemaSynchronizer
{
    public function __construct(
        private readonly PixieService $pixie,
        private SqlViewService $sqlViewService,
    ) {}

    public function sync(string $ownerCode, Config $config, string $schemaVersion = 'v1'): void
    {
        assert(false, "Populate the pixie from the registry, not from the yaml");
        // get the correct EM (sqlite file) + ensure ORM schema
        $ctx = $this->pixie->getReference($ownerCode);
        $em  = $ctx->em;
        $this->pixie->ensureSchema($em);

        // merge any "uses" from templates + parse property strings
        $templates = []; // optional: $this->pixie->getTemplates(); if you use it

        foreach ($config->getTables() as $coreName => $table) {
            // pk: first property if not set
            $pk = $table->getPkName();
            $props = [];

            // uses (optional)
            foreach ($table->getUses() as $code) {
                if (isset($templates['internal'])) {
                    foreach ($templates['internal']->getProperties() as $ip) {
                        if ($ip instanceof Property && $ip->getCode() === $code) {
                            $props[] = $ip;
                        }
                    }
                }
            }

            // explicit properties
            foreach ($table->getProperties() as $idx => $p) {
                if (is_string($p)) {
                    // minimal inline parser: "code:type" is enough; your Parser can do more
                    $parts = explode(':', $p, 2);
                    $p = new Property($parts[0]);
                }
                $props[] = $p;
                if (!$pk && $idx === 0) {
                    $pk = $p->getCode();
                }
            }
            $pk = $pk ?: 'id';

            // upsert CoreDefinition
            $coreDef = $ctx->repo(CoreDefinition::class)->findOneBy([
                'ownerCode' => $ownerCode,
                'core'      => $coreName,
            ]) ?? new CoreDefinition($ownerCode, $coreName, $pk, $schemaVersion);
            // update pk/version if changed
            $r = new \ReflectionClass($coreDef);
            $rp = $r->getProperty('pk');
            $rp->setAccessible(true);
            if ($rp->getValue($coreDef) !== $pk) { $rp->setValue($coreDef, $pk); }
            $rv = $r->getProperty('schemaVersion'); $rv->setAccessible(true);
            if ($rv->getValue($coreDef) !== $schemaVersion) { $rv->setValue($coreDef, $schemaVersion); }
            $em->persist($coreDef);

            // upsert FieldDefinitions
            $position = 0;
            foreach ($props as $prop) {
                $code = $prop->getCode();
                $kind = 'json_scalar';
                $target = $prop->getSubType() ?: null;
                $delim = method_exists($prop, 'getDelim') ? $prop->getDelim() : null;

                // crude kind inference (tweak if you have richer Property)
                if ($target && is_array($prop->getDefault() ?? null)) {
                    $kind = 'relation_many';
                } elseif ($target) {
                    $kind = 'relation_one';
                } elseif (\in_array($code, ['label'], true)) {
                    $kind = 'label';
                } elseif (is_array($prop->getDefault() ?? null)) {
                    $kind = 'json_array';
                }

                if (!$fd = $ctx->repo(FieldDefinition::class)->findOneBy([
                    'ownerCode'      => $ownerCode,
                    'core'           => $coreName,
                    'incomingHeader' => $code,
                ])) {
                    $fd = new FieldDefinition(
                        $ownerCode, $coreName, $code, $code, $kind, $target, $delim, /*translatable*/ false, $position
                    );
                }

                // update if needed (keep minimal to avoid setter boilerplate)
                $rf = new \ReflectionClass($fd);
                $map = [
                    'code'         => $code,
                    'kind'         => $kind,
                    'targetCore'   => $target,
                    'delim'        => $delim,
                    'translatable' => false,
                    'position'     => $position,
                ];
                foreach ($map as $propName => $val) {
                    $rp = $rf->getProperty($propName);
                    $rp->setAccessible(true);
                    if ($rp->getValue($fd) !== $val) {
                        $rp->setValue($fd, $val);
                    }
                }

                $em->persist($fd);
                $position++;
            }
        }

        $this->sqlViewService->createCoreViews($ownerCode, alsoPrefixed: true);


        $em->flush();
    }
}
