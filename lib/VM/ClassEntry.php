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

    public string $name;
    /** True for user enums registered via TYPE_DECLARE_ENUM (#1356). */
    public bool $isEnum = false;
    /** Parent class name (lowercase) for single inheritance (#101, #1231). */
    public ?string $parentLc = null;
    /** True for `interface` declarations (#1357). */
    public bool $isInterface = false;
    /** @var string[] lowercase interface names this class/interface implements or extends */
    public array $interfaces = [];
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
