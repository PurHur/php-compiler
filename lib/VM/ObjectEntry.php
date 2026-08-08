<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\VM;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Func;
// Bug in phan: https://github.com/phan/phan/issues/2661
// @phan-suppress-next-line PhanUnreferencedUseNormal
use PHPCompiler\Block;

class ObjectEntry {

    private static int $counter = 0;
    public ClassEntry $class;
    public int $id;

    public static function maxId(): int
    {
        return self::$counter;
    }
    /** @var array<string, Variable> bare name / primary slot (most-derived private or public/protected) */
    private array $properties = [];

    /**
     * Ancestor private slots shadowed by a same-name child private (#22521).
     *
     * Key: {@see PropertyMangle::shadowedPrivateKey()} (`declaringLc\0name`).
     *
     * @var array<string, Variable>
     */
    private array $shadowedPrivateProperties = [];

    /** Zend object property internal pointer (ext/standard/array.c; #11196). */
    private int $propertyInternalPointer = 0;

    /**
     * Zend zend_get_property_guard bits — prevent __get/__set/__isset/__unset re-entry (#25810).
     *
     * @see Zend/zend_object_handlers.c IN_GET / IN_SET / IN_ISSET / IN_UNSET
     */
    public const GUARD_IN_ISSET = 1;
    public const GUARD_IN_GET = 2;
    public const GUARD_IN_SET = 4;
    public const GUARD_IN_UNSET = 8;

    /** @var array<string, int> property name => guard bitmask */
    private array $propertyGuards = [];

    /**
     * Declared slots explicitly unset() — distinct from never-initialized typed UNDEF (#25810).
     *
     * @var array<string, true>
     */
    private array $explicitlyUnsetProperties = [];

    public ?Func $constructor = null;

    /** True after `__construct` returns (or immediately when none is defined). */
    public bool $constructed = false;

    /** Live Variable references holding this object (#3144). */
    public int $refCount = 0;

    /** True after user `__destruct()` has run (or when class has none). */
    public bool $destructorInvoked = false;

    /** User generator instance state (issue #167). */
    public ?GeneratorState $generatorState = null;

    /** DatePeriod Iterator cursor (#14228). */
    public ?DatePeriodIteratorState $datePeriodIterator = null;

    /** Anonymous function / closure body (issue #72). */
    public ?ClosureState $closureState = null;

    /** Closure target for ReflectionFunction instances (#4123). */
    public ?ClosureState $reflectionClosureState = null;

    /** True when ReflectionFunction wraps an ext/* internal builtin (#6678). */
    public bool $reflectionIsInternalFunction = false;

    /** Initializer for lazy proxy objects (#3317). */
    public ?ClosureState $lazyInitializer = null;

    /**
     * Original Closure object for ReflectionClass::getLazyInitializer() identity (#29152).
     *
     * Kept separately from {@see $lazyInitializer} so === matches Zend even if
     * ClosureState::ownerObject is rebound by wrapObject().
     */
    public ?ObjectEntry $lazyInitializerClosure = null;

    /** True until first property access or method call runs the lazy initializer. */
    public bool $lazyPending = false;

    /** True for ghost lazy objects (in-place init); false for proxy strategy (#4026). */
    public bool $lazyGhost = false;

    /**
     * User lazy flags (SKIP_INITIALIZATION_ON_SERIALIZE / SKIP_DESTRUCTOR) — php-src zend_lazy_object_info.flags (#21126).
     */
    public int $lazyUserFlags = 0;

    /** Archived initializer for ReflectionClass::resetAsLazyObject() (#6125). */
    public ?ClosureState $lazyResetInitializer = null;

    /** Archived Closure object for getLazyInitializer / resetAsLazyObject (#29152). */
    public ?ObjectEntry $lazyResetInitializerClosure = null;

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

    /** Canonical IANA id for DateTimeZone instances — survives scope temp clobber (#6041). */
    public ?string $dateTimeZoneName = null;

    /** Enum class on ReflectionEnumUnitCase / ReflectionEnumBackedCase — survives scope temp clobber (#16331, #6041). */
    public ?string $reflectionEnumClassName = null;

    /** Case name on ReflectionEnumUnitCase / ReflectionEnumBackedCase — survives scope temp clobber (#16331, #6041). */
    public ?string $reflectionEnumCaseName = null;

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
            $isPrivate = ($property->visibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0;
            // Child props are declared before inherited parent props — keep primary = most-derived.
            if ($isPrivate && isset($this->properties[$property->name])) {
                $this->shadowedPrivateProperties[PropertyMangle::shadowedPrivateKey($property)] = $var;
            } else {
                $this->properties[$property->name] = $var;
            }
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
            if (TypedPropertyCheck::isUninitialized($prop)) {
                continue;
            }
            ObjectLifetime::releaseDirectObject($prop);
            if (Variable::TYPE_INDIRECT === $prop->type) {
                $prop->resolveIndirect()->null();
            } else {
                $prop->null();
            }
        }
        foreach ($this->shadowedPrivateProperties as $prop) {
            if (TypedPropertyCheck::isUninitialized($prop)) {
                continue;
            }
            ObjectLifetime::releaseDirectObject($prop);
            if (Variable::TYPE_INDIRECT === $prop->type) {
                $prop->resolveIndirect()->null();
            } else {
                $prop->null();
            }
        }
        $this->generatorState = null;
        $this->datePeriodIterator = null;
        $this->closureState = null;
        $this->lazyInitializer = null;
        $this->lazyInitializerClosure = null;
        $this->lazyPending = false;
        $this->lazyGhost = false;
        $this->lazyUserFlags = 0;
        $this->lazyResetInitializer = null;
        $this->lazyResetInitializerClosure = null;
        $this->lazyInterfaceProxyTarget = null;
        $this->lazyRawInitializedProperties = [];
        $this->fiberState = null;
    }

    public function hasProperty(string $name): bool
    {
        if ($this->isEnumCase) {
            return EnumCaseSupport::propertyExistsOnCase($this->class, $name);
        }
        if (\PHPCompiler\ext\dom\DomNodePropertySupport::isManagedProperty($this, $name)) {
            return true;
        }
        if (\PHPCompiler\ext\dom\DomDocumentPropertySupport::isManagedProperty($this, $name)) {
            return true;
        }
        if (\PHPCompiler\ext\dom\DomTokenListPropertySupport::isManagedProperty($this, $name)) {
            return true;
        }
        if (\PHPCompiler\ext\dom\DomHtmlDocumentPropertySupport::isManagedProperty($this, $name)) {
            return true;
        }
        if (\PHPCompiler\ext\dom\DomHtmlElementPropertySupport::isManagedProperty($this, $name)) {
            return true;
        }
        if (\PHPCompiler\ext\xmlreader\XmlReaderPropertySupport::isManagedProperty($this, $name)) {
            return true;
        }

        return isset($this->properties[$name]);
    }

    /** True when this meta has a distinct instance slot (#22521). */
    public function hasPropertyForMeta(ClassProperty $meta): bool
    {
        if (($meta->visibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
            $shadowKey = PropertyMangle::shadowedPrivateKey($meta);
            if (isset($this->shadowedPrivateProperties[$shadowKey])) {
                return true;
            }
        }

        return $this->hasProperty($meta->name);
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
        if (\PHPCompiler\ext\dom\DomNodePropertySupport::isManagedProperty($this, $name)) {
            return \PHPCompiler\ext\dom\DomNodePropertySupport::getProperty($this, $name);
        }
        if (\PHPCompiler\ext\dom\DomDocumentPropertySupport::isManagedProperty($this, $name)) {
            return \PHPCompiler\ext\dom\DomDocumentPropertySupport::getProperty($this, $name);
        }
        if (\PHPCompiler\ext\dom\DomTokenListPropertySupport::isManagedProperty($this, $name)) {
            return \PHPCompiler\ext\dom\DomTokenListPropertySupport::getProperty($this, $name);
        }
        if (\PHPCompiler\ext\dom\DomHtmlDocumentPropertySupport::isManagedProperty($this, $name)) {
            return \PHPCompiler\ext\dom\DomHtmlDocumentPropertySupport::getProperty($this, $name);
        }
        if (\PHPCompiler\ext\dom\DomHtmlElementPropertySupport::isManagedProperty($this, $name)) {
            return \PHPCompiler\ext\dom\DomHtmlElementPropertySupport::getProperty($this, $name);
        }
        if (\PHPCompiler\ext\xmlreader\XmlReaderPropertySupport::isManagedProperty($this, $name)) {
            return \PHPCompiler\ext\xmlreader\XmlReaderPropertySupport::getProperty($this, $name);
        }
        if (!isset($this->properties[$name])) {
            throw new \LogicException('Undefined property access');
        }

        return $this->properties[$name];
    }

    /**
     * Slot for a specific ClassProperty — parent private when child shadows (#22521).
     */
    public function getPropertyForMeta(ClassProperty $meta): Variable
    {
        if (($meta->visibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
            $shadowKey = PropertyMangle::shadowedPrivateKey($meta);
            if (isset($this->shadowedPrivateProperties[$shadowKey])) {
                return $this->shadowedPrivateProperties[$shadowKey];
            }
        }

        return $this->getProperty($meta->name);
    }

    public function issetProperty(string $name): bool
    {
        // Dom\HTMLDocument computed props (body/title/…) ignore the null ClassProperty slot (#20540).
        $domHtmlIsset = \PHPCompiler\ext\dom\DomHtmlDocumentPropertySupport::propertyIsSet($this, $name);
        if (null !== $domHtmlIsset) {
            return $domHtmlIsset;
        }
        // Dom\Element::$id|/className|/innerHTML|/outerHTML (#20532).
        $domHtmlElIsset = \PHPCompiler\ext\dom\DomHtmlElementPropertySupport::propertyIsSet($this, $name);
        if (null !== $domHtmlElIsset) {
            return $domHtmlElIsset;
        }
        // Dom\* Node/CharacterData/ParentNode computed props (#21033, #21053, #21055).
        $domChildrenIsset = \PHPCompiler\ext\dom\DomNodePropertySupport::propertyIsSet($this, $name);
        if (null !== $domChildrenIsset) {
            return $domChildrenIsset;
        }
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
            // Declared property unset → UNDEF slot (typed Error vs untyped Warning on read, #4863/#22021).
            // Mark explicitly unset so post-unset magic (__get/__isset/__set) matches Zend (#25810).
            $slot->reset();
            $slot->type = Variable::TYPE_UNDEFINED;
            $slot->objectPropertyOwner = $this;
            $slot->objectPropertyName = $name;
            $this->explicitlyUnsetProperties[$name] = true;

            return;
        }
        // Dynamic property unset → remove slot (Zend zend_std_unset_property; #15750).
        unset($this->properties[$name]);
        unset($this->explicitlyUnsetProperties[$name]);
    }

    /** True after unset() on a declared property until the slot is written again (#25810). */
    public function isPropertyExplicitlyUnset(string $name): bool
    {
        return isset($this->explicitlyUnsetProperties[$name]);
    }

    public function clearPropertyExplicitlyUnset(string $name): void
    {
        unset($this->explicitlyUnsetProperties[$name]);
    }

    /**
     * Begin a magic-method property guard. Returns false when already active (skip re-entry).
     */
    public function beginPropertyGuard(string $name, int $flag): bool
    {
        $cur = $this->propertyGuards[$name] ?? 0;
        if (0 !== ($cur & $flag)) {
            return false;
        }
        $this->propertyGuards[$name] = $cur | $flag;

        return true;
    }

    public function endPropertyGuard(string $name, int $flag): void
    {
        if (!isset($this->propertyGuards[$name])) {
            return;
        }
        $this->propertyGuards[$name] &= ~$flag;
        if (0 === $this->propertyGuards[$name]) {
            unset($this->propertyGuards[$name]);
        }
    }

    public function isPropertyGuardActive(string $name, int $flag): bool
    {
        return 0 !== (($this->propertyGuards[$name] ?? 0) & $flag);
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
        foreach ($this->shadowedPrivateProperties as $shadowKey => $var) {
            if (!isset($clone->shadowedPrivateProperties[$shadowKey])) {
                $slot = new Variable(Variable::TYPE_NULL);
                $slot->objectPropertyOwner = $clone;
                $slot->objectPropertyName = $var->objectPropertyName;
                $clone->shadowedPrivateProperties[$shadowKey] = $slot;
            }
            $clone->shadowedPrivateProperties[$shadowKey]->copyFromForClone($var);
        }
        $clone->constructed = $this->constructed;
        // zend_closure_clone → zend_create_closure: zend_array_dup(static_variables) (#23489).
        // Sharing ClosureState made clone and original keep one static table (a1/b2/c3).
        if (null !== $this->closureState) {
            $clone->closureState = $this->closureState->cloneForObjectClone();
            $clone->closureState->ownerObject = $clone;
        } else {
            $clone->closureState = null;
        }
        $clone->lazyInitializer = $this->lazyInitializer;
        $clone->lazyPending = $this->lazyPending;
        $clone->lazyGhost = $this->lazyGhost;
        $clone->lazyUserFlags = $this->lazyUserFlags;
        $clone->lazyResetInitializer = $this->lazyResetInitializer;
        $clone->lazyInitException = $this->lazyInitException;
        $clone->lazyRawInitializedProperties = $this->lazyRawInitializedProperties;

        return $clone;
    }

    /**
     * Zend object property internal pointer — key() (ext/standard/array.c; #11196, #3312).
     */
    public function pointerKey(?Context $ctx = null): ?Variable
    {
        if (!$this->propertyPointerIsValid()) {
            return null;
        }
        $names = $this->propertyNameList();
        $name = $names[$this->propertyInternalPointer];
        $displayName = $name;
        if (null !== $ctx) {
            $meta = VmReflection::findClassProperty($this->class, $name, $ctx);
            if (null !== $meta) {
                $displayName = VmReflection::manglePropertyKey($meta, $ctx);
            }
        }
        $key = new Variable(Variable::TYPE_STRING);
        $key->string($displayName);

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
            return null;
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
