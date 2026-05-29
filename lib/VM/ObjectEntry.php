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
    }

    public function getProperty(string $name): Variable {
        if (!isset($this->properties[$name])) {
            if (!$this->class->allowsDynamicProperties) {
                throw new \LogicException("Undefined property access");
            }
            $var = new Variable(Variable::TYPE_NULL);
            $var->objectPropertyOwner = $this;
            $var->objectPropertyName = $name;
            $this->properties[$name] = $var;
        }

        return $this->properties[$name];
    }

    public function unsetProperty(string $name): void
    {
        if (!isset($this->properties[$name])) {
            return;
        }
        $this->properties[$name]->null();
    }

    public function getProperties(int $purpose): array {
        return $this->class->getProperties($this->properties, $purpose);
    }

    /** Shallow clone: new object id, copied instance property values. */
    public function cloneShallow(): self {
        $clone = new self($this->class);
        foreach ($this->properties as $name => $var) {
            $clone->properties[$name]->copyFrom($var);
        }
        $clone->constructed = $this->constructed;
        $clone->closureState = $this->closureState;

        return $clone;
    }

}
