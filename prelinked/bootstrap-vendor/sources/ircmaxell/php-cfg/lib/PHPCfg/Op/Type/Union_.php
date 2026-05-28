<?php

declare(strict_types=1);

namespace PHPCfg\Op\Type;

use PHPCfg\Op\Type;

class Union_ extends Type
{
    /** @var Type[] */
    public $types;

    public function __construct(array $types, array $attributes = [])
    {
        $this->types = $types;
    }
}
