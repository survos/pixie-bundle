<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\LocaleAwareInterface;

final class LocaleContext
{
    private string $current;
    private string $default;
    /** @var string[] */
    private array $enabled;

    public function __construct(
        private ?LocaleAwareInterface $translator = null,
        private ?LocaleSwitcher $switcher = null,
        ?ParameterBagInterface $params = null
    ) {
        $this->default = (string) ($params?->get('kernel.default_locale') ?? 'en');
        $enabled = $params?->get('kernel.enabled_locales') ?? [];
        $this->enabled = \is_array($enabled) ? $enabled : [];

        $this->current = $this->default;
        \Locale::setDefault($this->current);

        if ($this->switcher) {
            $this->switcher->setLocale($this->current);
        } elseif ($this->translator) {
            $this->translator->setLocale($this->current);
        }
    }

    public function get(): string
    {
        return $this->current;
    }

    public function getDefault(): string
    {
        return $this->default;
    }

    /**
     * @return list<string>
     */
    public function getEnabled(): array
    {
        return array_values($this->enabled);
    }

    public function set(string $locale): void
    {
        $locale = $this->normalize($locale);
        $this->assertSupported($locale);

        $this->current = $locale;
        \Locale::setDefault($locale);

        if ($this->switcher) {
            $this->switcher->setLocale($locale);
        } elseif ($this->translator) {
            $this->translator->setLocale($locale);
        }
    }

    public function run(string $locale, callable $callback): mixed
    {
        $prev = $this->get();
        $this->set($locale);
        try {
            return $callback();
        } finally {
            $this->set($prev);
        }
    }

    private function normalize(string $locale): string
    {
        return str_replace('_', '-', trim($locale));
    }

    private function assertSupported(string $locale): void
    {
        if ($this->enabled && !\in_array($locale, $this->enabled, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported locale "%s". Allowed: %s',
                $locale,
                implode(', ', $this->enabled)
            ));
        }
    }
}
