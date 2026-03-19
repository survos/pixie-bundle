<?php

namespace Survos\PixieBundle\Model;

class Source
{
    public function __construct(
        public ?string $dir=null,
        public string $label = '',
        public string $description = 'source description',
//        public ?string $locale = null, // in case it's not known during load.
        public string $units = 'cm',
        public string $country = 'us',
        public ?string $origin=null,
        public ?string $flickr_album_id = null,
        public ?string $license = null,
        public ?string $moderation = null,
        public string $instructions = 'installation instructions',
        public array $build = [],
        public array $make = [],
        public null|string|array $ignore = [],
        public null|string|array $include = [],
        public ?string $propertyCodeRule = 'snake', // during import, snake, camel or preserve keys (for properties)
        public int $total = 0,
        public array           $links=[],
        public ?int $approx_image_count=null,

    )
    {
        $this->instructions = trim($this->instructions);
    }

    public function isModerationNeeded(): bool
    {
        return $this->moderation !== 'none';
    }

}
