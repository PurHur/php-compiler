<?php

declare(strict_types=1);

namespace PHPCfg\Op\Type;

use PHPCfg\Op\Type;

class Intersection extends Type
{
    /** @var Type[] */
    public $types;

    public function __construct(array $types, array $attributes = [])
    {
        $this->types = $types;
    }
}
