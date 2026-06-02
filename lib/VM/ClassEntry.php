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
    /** True for `abstract class` declarations (#3385). */
    public bool $isAbstract = false;
    /** @var array<string, true> lowercase method names declared abstract on this class */
    public array $abstractMethods = [];
    /** @var array<string, string> trait FQCN => FQCN from direct `use Trait;` (#3119) */
    public array $usedTraits = [];
    /** @var list<string> */
    public array $interfaces = [];
    /** User method or VM builtin handler (issues #1360, #1366). */
    public ?Func $constructor = null;
    /** User `__destruct` when declared (#3144). */
    public ?Func $destructor = null;
    public array $properties = [];
    /** @var array<string, Func> method name (lowercase) => callable */
    public array $methods = [];
    /** @var array<string, int> method name (lowercase) => PHPCfg visibility flags */
    public array $methodVisibility = [];
    /** @var array<string, string> method name (lowercase) => declared casing (#3118) */
    public array $methodNames = [];
    /** @var array<string, Variable> constant name (lowercase) => value */
    public array $constants = [];
    /** @var array<string, string> lowercase enum case name => declared case name (#3420) */
    public array $enumCaseCanonicalNames = [];
    /** @var list<array{name: string, value: Variable}> enum cases in declaration order (#3308) */
    public array $enumCases = [];
    /** @var array<string, Variable> static property name (lowercase) => shared storage */
    public array $staticProperties = [];
    /** Readonly class: instance properties cannot change after construction (issue #1360). */
    public bool $readonly = false;
    /** Sealed class/interface: only listed types may extend/implement (#3322). */
    public bool $sealed = false;
    /** @var list<string> lowercase permitted child FQCNs; empty when sealed = none allowed */
    public array $sealedPermits = [];
    /** stdClass-style: create public properties on first read/write (#3117). */
    public bool $allowsDynamicProperties = false;
    /** @var list<string> PHP 8 attribute names on this class (#1936). */
    public array $attributeNames = [];
    /** @var list<\PHPCompiler\Compiler\AttributeEntry> class attributes with ctor args (#3206). */
    public array $attributeEntries = [];
    /** @var array<string, list<\PHPCompiler\Compiler\AttributeEntry>> enum case (lowercase) => attributes (#3800). */
    public array $enumCaseAttributeEntries = [];
    /** @var array<string, list<string>> method (lowercase) => attribute names (#1936). */
    public array $methodAttributeNames = [];
    /** @var array<string, list<\PHPCompiler\Compiler\AttributeEntry>> method attributes (#3206). */
    public array $methodAttributeEntries = [];
    /** @var array<string, list<string>> property (lowercase) => attribute names (#4136). */
    public array $propertyAttributeNames = [];
    /** @var array<string, list<\PHPCompiler\Compiler\AttributeEntry>> property attributes (#4136). */
    public array $propertyAttributeEntries = [];
    /** @var array<string, list<string>> class constant (lowercase) => attribute names (#4136). */
    public array $constAttributeNames = [];
    /** @var array<string, list<\PHPCompiler\Compiler\AttributeEntry>> class constant attributes (#4136). */
    public array $constAttributeEntries = [];
    /** @var array<string, list<\PHPCompiler\Compiler\ParameterMetadata>> method (lowercase) => params (#3340). */
    public array $methodParameterMetadata = [];
    /** @var array<string, string> method (lowercase) => trait FQCN when imported via use Trait (#3416). */
    public array $traitMethodSources = [];
    /** @var array<string, \PHPCompiler\Compiler\DeprecatedMetadata> method (lowercase) => deprecation (#3569). */
    public array $methodDeprecated = [];
    /** @var array<string, \PHPCompiler\Compiler\DeprecatedMetadata> constant (lowercase) => deprecation (#3569). */
    public array $constDeprecated = [];

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function getProperties(array $properties, int $reason): array {
        return $properties;
    }

}
