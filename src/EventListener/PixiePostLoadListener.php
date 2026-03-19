<?php
declare(strict_types=1);

namespace Survos\PixieBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Contract\TranslatableByCodeInterface;
use Survos\PixieBundle\Service\LocaleContext;
use Survos\PixieBundle\Service\TranslationResolver;

#[AsDoctrineListener(event: Events::postLoad, connection: 'pixie')]
final class PixiePostLoadListener
{
    public function __construct(
        private readonly LocaleContext $localeContext,
        private readonly TranslationResolver $resolver,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof TranslatableByCodeInterface) {
            return;
        }

        $codeMap = $entity->getStrCodeMap(); // field => str.code
        if ($codeMap === []) {
            return;
        }

        // Normalize codes
        $codes = [];
        foreach ($codeMap as $code) {
            $c = is_string($code) ? trim($code) : '';
            if ($c !== '') {
                $codes[] = $c;
            }
        }
        $codes = array_values(array_unique($codes));
        if ($codes === []) {
            return;
        }

        // Locale: prefer explicit current locale; fall back to default
        $loc = $this->localeContext->get() ?: $this->localeContext->getDefault();

        $texts = $this->resolver->textsFor($codes, $loc); // code => text
        if (!is_array($texts)) {
            $texts = [];
        }

        $this->logger?->debug('PixiePostLoadListener', [
            'class' => $entity::class,
            'locale' => $loc,
            'codes' => count($codes),
            'returned' => count($texts),
            'sample_code' => $codes[0] ?? null,
            'sample_text' => isset($codes[0]) ? ($texts[$codes[0]] ?? null) : null,
        ]);

        foreach ($codeMap as $field => $code) {
            $c = is_string($code) ? trim($code) : '';
            if ($c === '') {
                continue;
            }
            $entity->setResolvedTranslation((string)$field, $texts[$c] ?? null);
        }
    }
}
