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
    /** Backing scalar type name (`string` / `int`) for backed enums, or null for unit enums (#3083). */
    public ?string $backedType = null;
    /** Parent class name (lowercase) for single inheritance (#101, #1231). */
    public ?string $parentLc = null;
    /** True for `interface` declarations (#1357). */
    public bool $isInterface = false;
    /** True for `trait` declarations (#2312). */
    public bool $isTrait = false;
    /** @var array<string, string> trait FQCN => FQCN from direct `use Trait;` (#3119) */
    public array $usedTraits = [];
    /** @var list<string> */
    public array $interfaces = [];
    /** User method or VM builtin handler (issues #1360, #1366). */
    public ?Func $constructor = null;
    public array $properties = [];
    /** @var array<string, Func> method name (lowercase) => callable */
    public array $methods = [];
    /** @var array<string, int> method name (lowercase) => PHPCfg visibility flags */
    public array $methodVisibility = [];
    /** @var array<string, Variable> constant name (lowercase) => value */
    public array $constants = [];
    /** @var array<string, Variable> static property name (lowercase) => shared storage */
    public array $staticProperties = [];
    /** Readonly class: instance properties cannot change after construction (issue #1360). */
    public bool $readonly = false;
    /** stdClass-style: create public properties on first read/write (#3117). */
    public bool $allowsDynamicProperties = false;
    /** @var list<string> PHP 8 attribute names on this class (#1936). */
    public array $attributeNames = [];
    /** @var array<string, list<string>> method (lowercase) => attribute names (#1936). */
    public array $methodAttributeNames = [];

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function getProperties(array $properties, int $reason): array {
        // todo: implement __debug_info
        return $properties;
    }

}
