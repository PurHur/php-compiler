<?php

declare(strict_types=1);

/**
 * This file is part of PHP-CFG, a Control flow graph implementation for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCfg\Op\Stmt;

use PHPCfg\Block;
use PHPCfg\Op;
use PHPCfg\Op\Stmt;
use PhpCfg\Operand;

class Property extends Stmt
{
    public $name;

    public $visibility;

    /** Inferred type from php-types TypeReconstructor (bootstrap native AOT needs declared field). */
    public $type;

    public $static;

    public $defaultVar = null;

    public $defaultBlock = null;

    public $declaredType;

    public function __construct(Operand $name, int $visibility, bool $static, Op\Type $declaredType, Operand $defaultVar = null, Block $defaultBlock = null, array $attributes = [])
    {
        parent::__construct($attributes);
        $this->name = $this->addReadRef($name);
        $this->visibility = $visibility;
        $this->static = $static;
        $this->declaredType = $declaredType;
        if (null !== $defaultVar) {
            $this->defaultVar = $this->addReadRef($defaultVar);
        }
        $this->defaultBlock = $defaultBlock;
    }

    public function getVariableNames(): array
    {
        return ['name', 'defaultVar'];
    }

    public function getSubBlocks(): array
    {
        return ['defaultBlock'];
    }
}
