<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

/**
 * Centralized, defensive retrieval of PixieBundle Doctrine metadata.
 *
 * Important: this must remain resilient across DB switches. If getAllMetadata()
 * is empty after switching, we fall back to scanning Entity classes and loading
 * metadata explicitly.
 */
final class PixieEntityMetadataProvider
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return list<ClassMetadata>
     */
    public function getPixieMetadata(EntityManagerInterface $em): array
    {
        $mf = $em->getMetadataFactory();

        $all = [];
        try {
            $all = $mf->getAllMetadata();
        } catch (\Throwable $e) {
            $this->logger?->warning('getAllMetadata() threw, falling back to scan: '.$e->getMessage());
            $all = [];
        }

        $pixie = [];
        foreach ($all as $meta) {
            $name = $meta->getName();
            if (str_starts_with($name, 'Survos\\PixieBundle\\Entity\\')) {
                $pixie[] = $meta;
            }
        }

        if ($pixie !== []) {
            return $pixie;
        }

        $this->logger?->warning('No Pixie entity metadata from getAllMetadata(); scanning Entity directory.');

        $classes = $this->scanEntityClasses();
        $pixie = [];
        foreach ($classes as $class) {
            try {
                $pixie[] = $mf->getMetadataFor($class);
            } catch (\Throwable $e) {
                $this->logger?->warning(sprintf('Skipping %s: %s', $class, $e->getMessage()));
            }
        }

        if ($pixie === []) {
            throw new \RuntimeException('Could not load any PixieBundle entity metadata (scan fallback also failed).');
        }

        return $pixie;
    }

    /**
     * @return list<class-string>
     */
    private function scanEntityClasses(): array
    {
        $entityClasses = [];

        $possiblePaths = [
            $this->projectDir.'/vendor/survos/pixie-bundle/src/Entity',
            $this->projectDir.'/packages/pixie-bundle/src/Entity',
            \dirname(__DIR__).'/Entity',
        ];

        foreach ($possiblePaths as $entityDir) {
            if (!is_dir($entityDir) || !is_readable($entityDir)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->name('*.php')->in($entityDir);

            foreach ($finder as $file) {
                $relative = $file->getRelativePathname(); // includes subdirs
                $classPath = str_replace(['/', '.php'], ['\\', ''], $relative);
                $className = 'Survos\\PixieBundle\\Entity\\'.$classPath;

                $basename = $file->getBasename('.php');

                if (
                    str_ends_with($basename, 'Interface')
                    || str_ends_with($basename, 'Trait')
                    || str_contains($basename, 'Abstract')
                    || $basename === 'CoreEntity'
                ) {
                    continue;
                }

                if (class_exists($className)) {
                    $entityClasses[] = $className;
                }
            }

            if ($entityClasses !== []) {
                break;
            }
        }

        if ($entityClasses === []) {
            throw new \RuntimeException('No PixieBundle entity classes found while scanning.');
        }

        return $entityClasses;
    }
}
