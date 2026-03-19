<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Import\Persist;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\PixieBundle\Entity\Inst;
use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Import\Row\RowPayload;
use Survos\PixieBundle\Service\PixieService;
use Survos\PixieBundle\Util\ImportUtil;

final class RowWriter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PixieService $pixie,
        private readonly LoggerInterface $logger,
    ) {}

    public function upsert(\Survos\PixieBundle\Model\PixieContext $ctx, Inst $inst, RowPayload $p): Row
    {
        $core  = $this->pixie->getCore($p->core, $owner);
        $rowId = Row::RowIdentifier($core, $p->idWithinCore);

        /** @var Row $row */
        $row = $this->em->getRepository(Row::class)->find($rowId) ?? new Row($core, $p->idWithinCore);
        if ($row->id !== $rowId) { // PHP 8.4 property access; if your Row uses a different id naming, adjust here
            $this->em->persist($row);
        }

        // Prefer properties (PHP 8.4). If your Row still has setters, keep as fallback.
        if (property_exists($row, 'data')) { $row->data = $p->data; }
        elseif (method_exists($row, 'setData')) { $row->setData($p->data); }

        if (property_exists($row, 'label')) { $row->label = $p->label; }
        elseif (method_exists($row, 'setLabel')) { $row->setLabel($p->label); }

        if (property_exists($row, 'raw')) { $row->raw = $p->raw; }

        // Optional: content hash (store if you keep RowImportState)
        $hash = ImportUtil::contentHash([
            'schema' => $p->schemaVersion,
            'core'   => $p->core,
            'id'     => $p->idWithinCore,
            'data'   => $p->data,
            'label'  => $p->label,
        ]);

        return $row;
    }
}
