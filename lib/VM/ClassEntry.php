<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\VM;

use PHPCompiler\Func;
// bug in phan: https://github.com/phan/phan/issues/2661
// @phan-suppress-next-line PhanUnreferencedUseNormal
use PHPCompiler\Block;

class ClassEntry {

    const PROP_PURPOSE_DEBUG = 1;

    const KIND_CLASS = 0;
    const KIND_TRAIT = 1;
    const KIND_INTERFACE = 2;
    const KIND_ENUM = 3;

    public string $name;
    /** @see KIND_* — traits/interfaces/enums register when language support lands (#1371–#1373). */
    public int $kind = self::KIND_CLASS;
    /** Parent class name (lowercase) for single inheritance (#101, #1231). */
    public ?string $parentLc = null;
    public ?Func\PHP $constructor = null;
    public array $properties = [];
    /** @var array<string, Func\PHP> method name (lowercase) => callable */
    public array $methods = [];
    /** @var array<string, int> method name (lowercase) => PHPCfg visibility flags */
    public array $methodVisibility = [];
    /** @var array<string, Variable> constant name (lowercase) => value */
    public array $constants = [];
    /** @var array<string, Variable> static property name (lowercase) => shared storage */
    public array $staticProperties = [];

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function getProperties(array $properties, int $reason): array {
        // todo: implement __debug_info
        return $properties;
    }

}
