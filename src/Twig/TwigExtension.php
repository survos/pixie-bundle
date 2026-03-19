<?php

namespace Survos\PixieBundle\Twig;

use ApiPlatform\Metadata\UrlGeneratorInterface;
use Survos\MeiliBundle\Service\MeiliService;
use Survos\PixieBundle\Meta\PixieInterface;
use Survos\PixieBundle\Service\PixieMeiliSettingsFromConfig;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class TwigExtension extends AbstractExtension
{
    public function __construct(
        private PixieMeiliSettingsFromConfig $pixieMeili,
        private UrlGeneratorInterface $urlGenerator,
        private readonly ?RequestStack $requestStack=null,
//        #[Autowire('locale')] private string $locale
    )
    {

    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            // If your filter generates SAFE HTML, add ['is_safe' => ['html']]
            // Reference: https://twig.symfony.com/doc/3.x/advanced.html#automatic-escaping
            new TwigFilter('is_object', fn (mixed $s) => is_object($s)),
            new TwigFilter('is_array', fn (mixed $s) => is_array($s)),
            new TwigFilter('is_string', fn (mixed $s) => is_string($s)),
            new TwigFilter('array_is_list', fn (mixed $s) => is_array($s) && array_is_list($s)),
            new TwigFilter('file_exists', fn (string $s) => file_exists($s)),
            new TwigFilter('json_decode', fn (string $s, bool $asArray=true) => json_decode($s, $asArray)),
            new TwigFilter('t', function (object|array $obj, string $property) {
                $locale = $this->requestStack->getCurrentRequest()->getLocale();
                if ($_tr = $obj->{PixieInterface::TRANSLATED_STRINGS}??null) {
                    // is empty, or untranslated?
                    return $_tr->{$locale}->{$property}??null; // "$property-$locale-is-untranslated";
                } else {
//                    dd(obj: $obj);
                    return "translations-are-missing";
                }
            }, [
                'is_safe' => ['html'],
            ]),
            new TwigFilter('urlize', fn($x, $target='blank', ?string $label=null) =>
            filter_var($x, FILTER_VALIDATE_URL)
                ? sprintf('<a target="%s" href="%s">%s</a>', $target, $x, $label ?: $x)
                : $x, [
                'is_safe' => ['html'],
            ]),
        ];
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('insta_url', [$this, 'searchUrl']),
        ];
    }

    public function searchUrl(string $pixieCode, ?string $locale=null): ?string
    {
//        $indexName = $this->pixieMeili->indexName($pixieCode, $locale);
        return $this->urlGenerator->generate('meili_insta', ['indexName' => $pixieCode, '_locale' => $locale]);

    }
}
