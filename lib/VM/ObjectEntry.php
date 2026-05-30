<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\VM;

use PHPCompiler\Func;
// Bug in phan: https://github.com/phan/phan/issues/2661
// @phan-suppress-next-line PhanUnreferencedUseNormal
use PHPCompiler\Block;

class ObjectEntry {

    private static int $counter = 0;
    public ClassEntry $class;
    public int $id;
    private array $properties = [];
    public ?Func $constructor = null;

    /** True after `__construct` returns (or immediately when none is defined). */
    public bool $constructed = false;

    /** User generator instance state (issue #167). */
    public ?GeneratorState $generatorState = null;

    /** Anonymous function / closure body (issue #72). */
    public ?ClosureState $closureState = null;

    /** Initializer for lazy proxy objects (#3317). */
    public ?ClosureState $lazyInitializer = null;

    /** True until first property access or method call runs the lazy initializer. */
    public bool $lazyPending = false;

    /** True for backed/unit enum case singleton objects (#3518). */
    public bool $isEnumCase = false;

    /** Case name as declared (`Active`), not lowercased. */
    public ?string $enumCaseName = null;

    /** Backed scalar for backed enums; null for unit enums (#3404). */
    public ?Variable $enumCaseValue = null;

    /** PHP 8.1 fiber callback state (issue #3130). */
    public ?FiberState $fiberState = null;

    public function __construct(ClassEntry $class) {
        $this->class = $class;
        $this->id = ++self::$counter;
        $this->constructor = $class->constructor;
        foreach ($class->properties as $property) {
            $var = $property->getVariable();
            $var->objectPropertyOwner = $this;
            $var->objectPropertyName = $property->name;
            $this->properties[$property->name] = $var;
        }
        ObjectRegistry::register($this);
    }

    /** @return list<Variable> */
    public function instancePropertyVariables(): array
    {
        return array_values($this->properties);
    }

    /** @return array<string, Variable> */
    public function propertiesWithNames(): array
    {
        return $this->properties;
    }

    /** Break property edges and detach generator/closure state after cycle collection (#3113). */
    public function destroyForGc(): void
    {
        foreach ($this->properties as $prop) {
            if (Variable::TYPE_INDIRECT === $prop->type) {
                $prop->resolveIndirect()->null();
            } else {
                $prop->null();
            }
        }
        $this->generatorState = null;
        $this->closureState = null;
        $this->lazyInitializer = null;
        $this->lazyPending = false;
        $this->fiberState = null;
    }

    public function hasProperty(string $name): bool
    {
        return isset($this->properties[$name]);
    }

    public function allocateProperty(string $name): Variable
    {
        $var = new Variable(Variable::TYPE_NULL);
        $var->objectPropertyOwner = $this;
        $var->objectPropertyName = $name;
        $this->properties[$name] = $var;

        return $var;
    }

    public function getProperty(string $name): Variable {
        if ($this->isEnumCase) {
            return EnumCaseSupport::getProperty($this, $name);
        }
        if (!isset($this->properties[$name])) {
            throw new \LogicException('Undefined property access');
        }

        return $this->properties[$name];
    }

    public function issetProperty(string $name): bool
    {
        if (!isset($this->properties[$name])) {
            return false;
        }
        $var = $this->properties[$name]->resolveIndirect();

        return !$var->isUndefined() && Variable::TYPE_NULL !== $var->type;
    }

    public function unsetProperty(string $name): void
    {
        if (!isset($this->properties[$name])) {
            return;
        }
        $this->properties[$name]->null();
    }

    /** @return array<string, Variable> */
    public function getRawProperties(): array
    {
        return $this->properties;
    }

    public function getProperties(int $purpose, ?\PHPCompiler\VM $vm = null): array {
        if (ClassEntry::PROP_PURPOSE_DEBUG === $purpose && null !== $vm) {
            return $vm->getObjectDebugProperties($this);
        }

        return $this->class->getProperties($this->properties, $purpose);
    }

    /**
     * Zend {@see compare_objects()} default handler: same class and equal property values (#3602).
     */
    public function looseEquals(self $other): bool
    {
        if ($this === $other) {
            return true;
        }
        if ($this->class->name !== $other->class->name) {
            return false;
        }
        $names = array_keys($this->properties);
        foreach (array_keys($other->properties) as $name) {
            if (!\in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        foreach ($names as $name) {
            $left = isset($this->properties[$name])
                ? $this->properties[$name]->resolveIndirect()
                : new Variable(Variable::TYPE_NULL);
            $right = isset($other->properties[$name])
                ? $other->properties[$name]->resolveIndirect()
                : new Variable(Variable::TYPE_NULL);
            if (!$left->equals($right)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Zend {@see zend_compare_objects()} / {@see zend_std_compare_objects()} for spaceship (#3691).
     *
     * Same class: compare instance properties via {@see Variable::compareSpaceship()}.
     * Different classes on PHP 8.2: always 1 (not a total order; matches Zend <=>).
     */
    public function compareSpaceship(self $other): int
    {
        if ($this === $other) {
            return 0;
        }
        if ($this->class->name !== $other->class->name) {
            return 1;
        }
        $names = array_keys($this->properties);
        foreach (array_keys($other->properties) as $name) {
            if (!\in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        foreach ($names as $name) {
            $left = isset($this->properties[$name])
                ? $this->properties[$name]->resolveIndirect()
                : new Variable(Variable::TYPE_NULL);
            $right = isset($other->properties[$name])
                ? $other->properties[$name]->resolveIndirect()
                : new Variable(Variable::TYPE_NULL);
            $cmp = Variable::compareSpaceship($left, $right);
            if (0 !== $cmp) {
                return $cmp;
            }
        }

        return 0;
    }

    /** Shallow clone: new object id, copied instance property values. */
    public function cloneShallow(): self {
        $clone = new self($this->class);
        foreach ($this->properties as $name => $var) {
            $clone->properties[$name]->copyFrom($var);
        }
        $clone->constructed = $this->constructed;
        $clone->closureState = $this->closureState;
        $clone->lazyInitializer = $this->lazyInitializer;
        $clone->lazyPending = $this->lazyPending;

        return $clone;
    }

}
