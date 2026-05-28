<?php

declare(strict_types=1);

/**
 * This file is part of PHP-CFG, a Control flow graph implementation for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCfg\Op\Expr;

use PHPCfg\Op\Expr;

class Array_ extends Expr
{
    public $keys;

    public $values;

    public $byRef;

    /** @var list<bool> parallel to values; true when element is ...$expr (issue #141). */
    public $unpack;

    public function __construct(array $keys, array $values, array $byRef, array $unpack = [], array $attributes = [])
    {
        parent::__construct($attributes);
        $this->keys = $this->addReadRefs(...$keys);
        $this->values = $this->addReadRefs(...$values);
        $this->byRef = $byRef;
        $this->unpack = $unpack;
    }

    public function getVariableNames(): array
    {
        return ['keys', 'values', 'result'];
    }
}
