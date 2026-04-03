<?php

namespace Survos\PixieBundle\Service;

// see https://weglot.com/blog/machine-translation-software/
use App\Entity\Loc;
use App\Entity\LabelInterface;
use App\Entity\Project;
use App\Entity\ProjectInterface;
use Doctrine\ORM\EntityManagerInterface;
use Flintstone\Flintstone;
use Flintstone\Formatter\JsonFormatter;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Survos\GridGroupBundle\Model\Schema;
use Survos\GridGroupBundle\Service\CsvDatabase;
use Survos\Scraper\Service\ScraperService;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;
use Symfony\Component\Cache\Adapter\PdoAdapter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Intl\Languages;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use function Symfony\Component\String\u;

class LibreTranslateService
{
    public function __construct(
//        private CacheInterface $Cache,
// see pools in cache.yaml
        private readonly LoggerInterface           $logger,
        private readonly ParameterBagInterface     $bag,
        private readonly HttpClientInterface       $client,
        private readonly ScraperService            $scraperService,
        #[Autowire("%env(DEEPL_API_KEY)%")]
        private readonly string $deepLApiKey
    )
    {
//        $this->scraperService->setDir('/tmp');
    }


    public function getAlternatives(string $line, ?string $engine = null)
    {
        $alternatives =
            array_unique(
                [$line,
                    u($line)->lower()->toString(),
                    u($line)->title(false)->toString(), ucfirst(strtolower($line))]
            );
        return $alternatives;
    }

    public function translate(string   $q, $from = 'en', $to = 'es', $format = 'text',
                              ?string   $engine = null,
                              ?callable $callable = null
    ): ?string
    {
        static $count = 0;
        static $cache = [];
        assert(false, "appears to be moved to translateLine()");


        assert(in_array($engine, ['libre', 'deepl']), "bad engine: " . $engine);
        if (is_numeric($q)) {
            return null;
        }
        $q = trim($q, '. ');

        // first, check our overrides
        /** @var MessageCatalogue $c */
        $c = $this->translator->getCatalogue($from);
        $domain = 'glossary';
        if ($c->has($q, domain: $domain)) {
            dd($domain, $q);
            return $this->translator->trans($q, domain: $domain);
        }

        //

        $translatedText = [];

        foreach (explode("\n\n", $q) as $paragraph)
        {
            // this isn't right for penn, especially fields. Need to look for line endings, or not do this.
            foreach (explode("\n", trim($paragraph)) as $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                if ($line == strtoupper($line)) {
                    $line = u($line)->lower()->toString();
                }
                $alternatives = $this->getAlternatives($line);

                // @todo: add rules, so we can re-apply origin, title, upcase, if there's no translation
                foreach ($alternatives as $alternative) {
//                    $this->logger->warning("Alternative: " . substr($alternative, 0, 60) . ' of ' . count($alternatives));
                    $translatedLine = null;
                    {
                        $key = self::getKey($alternative, $engine);
                        if (in_array($key, $cache)) {
                            continue;
                        }
                        $cache[] = $key;
                        // if deepl, it should be in the cache (testing)
                        if ($engine == 'deepl') {
                            $this->logger->warning("Missing " . $alternative);
                            $count += strlen((string) $alternative);
//                            $translatedLine = $this->translateLine($alternative, from: $from, to: $to, format: $format, engine: 'libre', transCache: $transCache);
                            continue;
                            dd($alternative, $engine, $transCache->getFilename());
                        } else {
                            $count += strlen((string) $alternative);
                            assert(false);
//                            $this->logger->warning("About to call translateLine with " . substr($alternative, 0, 60));
                            $translatedLine = $this->translateLine($alternative, from: $from, to: $to,
                                engine: $engine,
                                callable: $callable
                            );
//                            $this->logger->warning("Received $translatedLine");
                        }
                    }
                    // hacks for libre, capitalization issues
                    if ($translatedLine <> $alternative) {
                        break;
                    }
                }
                if ($translatedLine) {
                    $translatedText[] = $translatedLine;
                }
            }
//            $translatedText[] = '';
        }
//        $this->logger->warning("Count now at " . $count);
        return trim(implode("\n", $translatedText));


//        if (count(explode("\n", $q)) > 1 ) {
//            dd($q, join("\n", $translatedText));
//        }
    }

    public function getSourceCacheManager(string $sourceDir)
    {

    }




    public function loadCache(CsvDatabase $transCache): array
    {
        $lookupCache = [];
        foreach ($transCache->readFromFile() as $row) {
            AppService::assertKeyExists('key', $row);
            $key = $row['key'];
            $lookupCache[$key] = $row['target'];
        }
        return $lookupCache;

    }


    public function detect(?string $source): ?array
    {
        if (empty($source)) {
            return null;
        }
//        LIBRE_URL=http://localhost:5000/translate
        $base = $this->bag->get('libre_url');
        $url = str_replace('/translate', '/detect', $base);
        $params = [
            'q' => substr($source, 0, 255), // hack, there's issues sometimes, probably need a retry
        ];
        $key = md5(json_encode($params));
        $value = $this->cache->get($key, function (ItemInterface $item) use ($url, $params) {
            $request = $this->client->request('POST', $url, [
                'body' => $params
            ]);
            // @todo
            return $request->toArray()[0] ?? null;
            try {
            } catch (\Exception $exception) {
//                dump($exception->getMessage());
                $this->logger->warning($exception->getMessage() . "\n\n" . $params['q']);
                return null;
            }

        });
        if (array_is_list($value)) {
            $value = $value[0];
        }
        if (empty($value['language'])) {
            dd($source, $key);
        }
        return $value;
        if (count($value)) {
            dd($value);
            assert(array_is_list($value));
            return $value[0];
        }
        return null;
//        $this->client->request('POST', $url, ['body' => $params])->toArray());
        return $value;


    }




    /**
     * Preserves the key structure, for manual translation of a file.
     * Otherwise, flatten first, and just use the glossary
     *
     * @param array $yaml
     * @param string $targetLocale
     * @param string $engine
     * @return array
     */
    public function translateYaml(array $yaml, string $targetLocale, string $engine): array
    {
        foreach ($yaml as $key => $value) {
//            dump("Transating $currentKey.$key", $value);
            if (is_array($value)) {
                $translatedTree = $this->translateYaml($value, $targetLocale, engine: $engine);
                $new[$key] = $translatedTree;
//                dump($value, $translatedTree);
//                if ($currentKey) {
//                    $new[$currentKey][$key] = $translatedTree;;
//                } else {
//                }
            } else {
                $value = trim((string) $value);
                $new[$key] = trim((string) $this->translate($value, to: $targetLocale, engine: $engine));
//                $new[$key] = '!' . $targetLocale . '  ' .  $value;
            }
        }
//        dump($new);
        return $new;
    }

    /**
     * @param string $url
     * @param array $params
     * @param string $source
     * @param string $from
     * @param string $to
     * @param callable $engineCallable
     * @param callable|null $callable
     * @return string|null
     * @throws InvalidArgumentException
     */
    public function fetchTranslation(string    $url,
                                     array $params,
                                     string    $sourceKeyName, // for the alternatives
                                     string    $source, // the original source,
                                     string    $from,
                                     string    $to,
                                     callable  $engineCallable,
                                     ?callable $callable = null): ?string
    {
        // this is the http hash (scraperCache)! not the transCache.  Worthwhile for testing?
//        $transCacheKey = $this->getKey($source, $to);
        $source = u($source)->toString();
        $source = trim($source);
        $source = rtrim($source, '.');
        $alternatives = $this->getAlternatives($source);


        if (is_numeric($source)) {
            return $source;
        }
        if ($source == '') {
            return $source;
        }

        foreach ($alternatives as $alternative) {
            $params[$sourceKeyName] = $alternative;
            $md5 = hash('xxh3', 'x' . $url . json_encode($params));
                $content = $this->scraperService->fetchUrl($url, $params, [
                    'Authorization' => $this->deepLApiKey
                ], $md5, 'POST');

                $response = is_string($content) ? json_decode($content, true) : $content;
                if (!is_array($response)) {
                    $value = null;
                    continue;
                }
//                dd($response);
                // fetch now returns the status code, and the real data in ['data']
                if (array_key_exists('data', $response)) {
                    $responseData = (array)$response['data'];
                } else {
                    $responseData = $response;
                }
                if (!array_key_exists('translatedText', $responseData)) {
                    dd($url, $responseData, $content, $source );
                    $this->logger->error("Error {$content['statusCode']} translating " . $source);
                    return null;
                }
                $value = $responseData['translatedText'];
//                $value = $engineCallable(json_decode($content));
//                dd($value, $url, $params, $responseData);
            try {
            } catch (\Exception $exception) {
                $this->logger->error($exception->getMessage() . sprintf("\n%s->%s\n%s", $from, $to, $source));
//                dd($exception, $request, $params);
                $value = null;
            }
//            if ($alternative == 'Muschels') {
//                dd($alternative);
//            }
            if ($source <> $value) {
                break; // break out if we get a translation
//            } else {
//                dump($params, $alternative, $value);
            }
        }
        //
//        str_contains($source, 'Busto de ') && dd($source, $value);
        return $value;
    }

    // this stores translations to the database and the source/translation cache.  So good for project,owner,fields, and also for indexing objects
    public function translateRow(array    $row,
                                 Schema   $schema,
                                 string   $sourceLocale,
                                 string   $targetLocale,
                                 string   $engine = 'libre',
                                 ?string   $cacheKeyBase = null,
                                 ?callable $callable = null
    ):
    array
    {
        $translatedRow = [];
        foreach ($row as $var => $value) {
            $property = $schema->getProperty($var);
            if ($property->isTranslatable()) {
                $source = u($value)->toString();
                $source = trim($source);
                $source = rtrim($source, '.');
                if (is_numeric($source)) {
                    continue;
                }

                $transCacheKey = $this->getKey($value, $engine, $cacheKeyBase);

                // return value is just for debugging.
                $translatedRow[$transCacheKey] = $this->translateLine(
                    $value,
                    transCacheKey: $transCacheKey,
                    to: $targetLocale,
                    from: $sourceLocale,
                    engine: $engine,
                    callable: $callable
                );
            }
        }
//        dump($translatedRow);
//        if (count($translatedRow)) dump($translatedRow);

        return $translatedRow;
    }

    // faster (batch the label and description) and in theory could provide context, but doesn't in libre
    private function translateRowAsHtml()
    {
//        foreach ($row as $var => $value) {
//            $property = $schema->getProperty($var);
//            if ($property->isTranslatable()) {
//                $x[] = sprintf('<div id="%s">%s</div>', $property->getCode(), $value);
//                $toTranslate[$property->getCode()] = $value;
//            }
//        }
////        dd($toTranslate);
//        $translatedValue = $this->translate(
//            join("\n", $x),
//            to: $targetLocale,
//            from: $sourceLocale,
//            engine: $engine,
//            format: 'html',
//        );
//
//        $translationsByProperty = [];
//        if (preg_match_all('|<div id="(.*?)">(.*?)</div>|sm', $translatedValue, $mm, PREG_SET_ORDER)) {
//            // stores these in the entity (not string) translation cache
//            $translationsByProperty = ['id' => $row['id']];
//            foreach ($mm as $m) {
//                $translationsByProperty[$m[1]] = $m[2];
//            }
////            $translationsByProperty = array_reduce($mm, function($value, $key, array $carry) {
////                $carry[$key] = $value; return $carry;
////            });
//        } else {
////            assert(false, 'bad regex ');
//        }
//        return $translationsByProperty;
//
    }


}
