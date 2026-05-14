<?php

namespace Survos\PixieBundle\Entity;

use Survos\DataContracts\Vocabulary\MuseumVocab;

/**
 * Pixie entity/table-type codes.
 *
 * Extends MuseumVocab so museum authority codes (cul, tec, mat, …) are
 * available on any class implementing CoreInterface without re-declaring them.
 */
interface CoreInterface extends MuseumVocab
{
    // ── People / organisations ────────────────────────────────────────────────
    public const PERSON = 'per';
    public const ORG    = 'org';

    // ── Object types ──────────────────────────────────────────────────────────
    public const OBJECT     = 'obj';
    public const LOT        = 'lot';
    public const EXPOSITION = 'expo';
    public const LIST       = 'lst';
    public const SET        = 'set';
    public const ITEM       = 'set_item';

    // ── Logistics ─────────────────────────────────────────────────────────────
    public const LOAN    = 'loan';
    public const STORAGE = 'loc';

    // ── Events / time ─────────────────────────────────────────────────────────
    public const EVENT    = 'evt';
    public const TIMELINE = 'time';
    public const TAG      = 'tag';

    // ── Rights ────────────────────────────────────────────────────────────────
    public const DATA_RIGHTS  = 'c_data';
    public const IMAGE_RIGHTS = 'c_images';

    // ── Relationship / attribute types ────────────────────────────────────────
    public const RELATIONSHIP_TYPE = 'rt';
    public const MEAS              = 'meas';
    public const MASS              = 'mass';
    public const SCALE             = 'scale';
    public const CLASSIFICATION    = 'cla';
    public const COORD             = 'coord';
    public const KEYWORDS          = 'key';
}
