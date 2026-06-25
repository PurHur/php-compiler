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
    /** @var array<string, Variable> */
    private array $properties = [];

    /** Zend object property internal pointer (ext/standard/array.c; #11196). */
    private int $propertyInternalPointer = 0;

    public ?Func $constructor = null;

    /** True after `__construct` returns (or immediately when none is defined). */
    public bool $constructed = false;

    /** Live Variable references holding this object (#3144). */
    public int $refCount = 0;

    /** True after user `__destruct()` has run (or when class has none). */
    public bool $destructorInvoked = false;

    /** User generator instance state (issue #167). */
    public ?GeneratorState $generatorState = null;

    /** Anonymous function / closure body (issue #72). */
    public ?ClosureState $closureState = null;

    /** Closure target for ReflectionFunction instances (#4123). */
    public ?ClosureState $reflectionClosureState = null;

    /** True when ReflectionFunction wraps an ext/* internal builtin (#6678). */
    public bool $reflectionIsInternalFunction = false;

    /** Initializer for lazy proxy objects (#3317). */
    public ?ClosureState $lazyInitializer = null;

    /** True until first property access or method call runs the lazy initializer. */
    public bool $lazyPending = false;

    /** True for ghost lazy objects (in-place init); false for proxy strategy (#4026). */
    public bool $lazyGhost = false;

    /** Archived initializer for ReflectionClass::resetAsLazyObject() (#6125). */
    public ?ClosureState $lazyResetInitializer = null;

    /** Pending initializer failure for ReflectionClass::getLazyInitializationException() (#6514). */
    public ?ObjectEntry $lazyInitException = null;

    /** Concrete instance behind an interface lazy proxy after factory runs (#9999). */
    public ?ObjectEntry $lazyInterfaceProxyTarget = null;

    /**
     * Instance properties written via ReflectionProperty::setRawValueWithoutLazyInitialization() (#7095).
     *
     * @var array<string, true>
     */
    public array $lazyRawInitializedProperties = [];

    /** True for backed/unit enum case singleton objects (#3518). */
    public bool $isEnumCase = false;

    /** Case name as declared (`Active`), not lowercased. */
    public ?string $enumCaseName = null;

    /** Backed scalar for backed enums; null for unit enums (#3404). */
    public ?Variable $enumCaseValue = null;

    /** PHP 8.1 fiber callback state (issue #3130). */
    public ?FiberState $fiberState = null;

    /** PHP 8.4 Resource object handle payload (#7073). */
    public ?ResourceState $resourceState = null;

    /** True after readonly($object) marks this instance immutable (#6485). */
    public bool $dynamicReadonly = false;

    /** Manual `new Throwable()` stack before throw overwrites trace (#9905, zend_exceptions.c). */
    public ?Variable $manualConstructTrace = null;

    /**
     * Readonly property names allowed one write during clone-with (#7250, IS_PROP_REINITABLE).
     *
     * @var array<string, true>
     */
    public array $reinitableProperties = [];

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
            ObjectLifetime::releaseDirectObject($prop);
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
        $this->lazyGhost = false;
        $this->lazyResetInitializer = null;
        $this->lazyInterfaceProxyTarget = null;
        $this->lazyRawInitializedProperties = [];
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
        $slot = $this->properties[$name];
        foreach ($this->class->properties as $property) {
            if ($property->name !== $name) {
                continue;
            }
            // Declared property unset → uninitialized slot (typed with/without default, #4863).
            $slot->reset();
            $slot->type = Variable::TYPE_UNDEFINED;
            $slot->objectPropertyOwner = $this;
            $slot->objectPropertyName = $name;

            return;
        }
        $slot->null();
    }

    /** @return array<string, Variable> */
    public function getRawProperties(): array
    {
        return $this->properties;
    }

    public function getProperties(int $purpose, ?\PHPCompiler\VM $vm = null, ?\PHPCompiler\Frame $frame = null): array {
        if (ClassEntry::PROP_PURPOSE_DEBUG === $purpose && null !== $vm) {
            return $vm->getObjectDebugProperties($this, $frame);
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
        if (EnumCaseSupport::isEnumCase($this) && EnumCaseSupport::isEnumCase($other)) {
            return EnumCaseSupport::compareEquals($this, $other);
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
        if (EnumCaseSupport::isEnumCase($this) && EnumCaseSupport::isEnumCase($other)) {
            return EnumCaseSupport::compareSpaceship($this, $other);
        }
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
            $dest = $clone->hasProperty($name)
                ? $clone->getProperty($name)
                : $clone->allocateProperty($name);
            $dest->copyFromForClone($var);
        }
        $clone->constructed = $this->constructed;
        $clone->closureState = $this->closureState;
        $clone->lazyInitializer = $this->lazyInitializer;
        $clone->lazyPending = $this->lazyPending;
        $clone->lazyGhost = $this->lazyGhost;
        $clone->lazyResetInitializer = $this->lazyResetInitializer;
        $clone->lazyInitException = $this->lazyInitException;
        $clone->lazyRawInitializedProperties = $this->lazyRawInitializedProperties;

        return $clone;
    }

    /**
     * Zend object property internal pointer — key() (ext/standard/array.c; #11196).
     */
    public function pointerKey(): ?Variable
    {
        if (!$this->propertyPointerIsValid()) {
            return null;
        }
        $names = $this->propertyNameList();
        $key = new Variable(Variable::TYPE_STRING);
        $key->string($names[$this->propertyInternalPointer]);

        return $key;
    }

    /**
     * Zend object property internal pointer — current()/pos() (ext/standard/array.c; #11196).
     */
    public function pointerCurrent(): ?Variable
    {
        if (!$this->propertyPointerIsValid()) {
            return null;
        }

        return $this->propertyValueAt($this->propertyInternalPointer);
    }

    /**
     * Zend object property internal pointer — next() (ext/standard/array.c; #11196).
     */
    public function pointerNext(): ?Variable
    {
        $count = $this->propertyCount();
        if (0 === $count) {
            $this->propertyInternalPointer = self::INVALID_PROPERTY_POINTER;

            return null;
        }
        if ($this->propertyInternalPointer >= $count) {
            return null;
        }
        $start = self::INVALID_PROPERTY_POINTER === $this->propertyInternalPointer
            ? 0
            : $this->propertyInternalPointer + 1;
        if ($start >= $count) {
            $this->propertyInternalPointer = $count;

            return null;
        }
        $this->propertyInternalPointer = $start;

        return $this->propertyValueAt($start);
    }

    /**
     * Zend object property internal pointer — prev() (ext/standard/array.c; #11196).
     */
    public function pointerPrev(): ?Variable
    {
        $count = $this->propertyCount();
        if (0 === $count) {
            $this->propertyInternalPointer = self::INVALID_PROPERTY_POINTER;

            return null;
        }
        if ($this->propertyInternalPointer >= $count) {
            $last = $count - 1;
            $this->propertyInternalPointer = $last;

            return $this->propertyValueAt($last);
        }
        if (self::INVALID_PROPERTY_POINTER === $this->propertyInternalPointer) {
            return null;
        }
        $before = $this->propertyInternalPointer - 1;
        if ($before < 0) {
            $this->propertyInternalPointer = self::INVALID_PROPERTY_POINTER;

            return null;
        }
        $this->propertyInternalPointer = $before;

        return $this->propertyValueAt($before);
    }

    /**
     * Zend object property internal pointer — reset() (ext/standard/array.c; #11196).
     */
    public function pointerReset(): ?Variable
    {
        if (0 === $this->propertyCount()) {
            $this->propertyInternalPointer = self::INVALID_PROPERTY_POINTER;

            return null;
        }
        $this->propertyInternalPointer = 0;

        return $this->propertyValueAt(0);
    }

    /**
     * Zend object property internal pointer — end() (ext/standard/array.c; #11196).
     */
    public function pointerEnd(): ?Variable
    {
        $count = $this->propertyCount();
        if (0 === $count) {
            $this->propertyInternalPointer = self::INVALID_PROPERTY_POINTER;

            return null;
        }
        $last = $count - 1;
        $this->propertyInternalPointer = $last;

        return $this->propertyValueAt($last);
    }

    private const INVALID_PROPERTY_POINTER = -1;

    /** @return list<string> */
    private function propertyNameList(): array
    {
        return array_keys($this->properties);
    }

    private function propertyCount(): int
    {
        return \count($this->properties);
    }

    private function propertyPointerIsValid(): bool
    {
        return $this->propertyInternalPointer >= 0
            && $this->propertyInternalPointer < $this->propertyCount();
    }

    private function propertyValueAt(int $index): ?Variable
    {
        $names = $this->propertyNameList();
        if (!isset($names[$index])) {
            return null;
        }
        $name = $names[$index];
        $result = new Variable();
        foreach ($this->class->properties as $property) {
            if ($property->name !== $name) {
                continue;
            }
            if (!$this->hasProperty($name)) {
                $result->copyFrom($property->getVariable());

                return $result;
            }
            $resolved = $this->getProperty($name)->resolveIndirect();
            if (
                Variable::TYPE_NULL === $resolved->type
                || $resolved->isUndefined()
                || TypedPropertyCheck::isUninitialized($resolved)
            ) {
                $result->copyFrom($property->getVariable());

                return $result;
            }
            $result->copyFrom($resolved);

            return $result;
        }
        if (!isset($this->properties[$name])) {
            return null;
        }
        $result->copyFrom($this->properties[$name]->resolveIndirect());

        return $result;
    }

}
