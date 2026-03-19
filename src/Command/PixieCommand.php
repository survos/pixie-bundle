<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Command;

use Survos\DataBundle\Command\DataCommand;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Component\DependencyInjection\Attribute\Required;

/**
 * Base command for all pixie-bundle commands.
 *
 * Provides via #[Required]:
 *   $this->dataPaths    (from DataCommand)
 *   $this->pixieService
 *
 * Usage:
 *   final class PixieMigrateCommand extends PixieCommand { ... }
 */
abstract class PixieCommand extends DataCommand
{
    protected PixieService $pixieService;

    #[Required]
    public function setPixieService(PixieService $pixieService): void
    {
        $this->pixieService = $pixieService;
    }
}
