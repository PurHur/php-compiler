<?php

declare(strict_types=1);

/**
 * This file is part of PHP-CFG, a Control flow graph implementation for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCfg\Op\Terminal;

use PHPCfg\Op\Type;
use PHPCfg\Block;
use PHPCfg\Op\Terminal;
use PHPCfg\Operand;

class Const_ extends Terminal
{
    public $name;

    public $value;

    public $valueBlock;

    /** PHP 8.3+ typed class constant declaration; null when untyped. */
    public ?Type $declaredType = null;

    /** PhpParser Stmt\ClassConst flags (MODIFIER_FINAL, visibility, etc.). */
    public int $flags = 0;

    /** True for `case Name = value` in enums; false for `const` in enum/class bodies (#5054). */
    public bool $isEnumCase = false;

    /** True when enum case declares `= value`; false for unit enum implicit case (#5397). */
    public bool $enumCaseHasExplicitValue = false;

    public function __construct(Operand $name, Operand $value, Block $valueBlock, array $attributes = [])
    {
        parent::__construct($attributes);
        $this->name = $this->addReadRef($name);
        $this->value = $this->addReadRef($value);
        $this->valueBlock = $valueBlock;
    }

    public function getVariableNames(): array
    {
        return ['name', 'value'];
    }

    public function getSubBlocks(): array
    {
        return ['valueBlock'];
    }
}
