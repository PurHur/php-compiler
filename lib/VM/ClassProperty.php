<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\VM;

use PHPCompiler\Block;

class ClassProperty {

    public string $name;
    public ?Variable $default;
    public Variable $prototype;
    public ?string $setHookMethodLc = null;
    /** Lowercase get-hook method name from property-hooks lowering (#3145), or null. */
    public ?string $getHookMethodLc = null;
    /** Get hook accepts call-site arguments via `$obj->prop(...)` (#18172, PHP 8.4). */
    public bool $getHookParameterized = false;
    /** `&get` returns by reference — dim writes mutate through the ref (#21098, zend_property_hooks.c). */
    public bool $getHookByRef = false;
    /** Lowercase unset-hook method name from property-hooks lowering (#6502), or null. */
    public ?string $unsetHookMethodLc = null;
    /** Virtual hooked property: hooks do not use backing storage (#4687, Zend zend_property_hooks.c). */
    public bool $propertyHookVirtual = false;
    /** Final property (ZEND_ACC_FINAL / ReflectionProperty::isFinal, #20511, #22241). */
    public bool $propertyFinal = false;
    /** Individual readonly property (issue #3149, promoted readonly #3432). */
    public bool $readonly = false;
    /** PHP 8.4 lazy modifier — default initializer runs on first read (#16813). */
    public bool $lazy = false;
    /** Constructor promotion (#7383, ext/reflection/php_reflection.c reflection_property_is_promoted). */
    public bool $fromConstructorPromotion = false;
    /** Per-instance `new` default initializer (issue #3391). */
    public ?Block $defaultInitBlock = null;
    public ?int $defaultInitResultSlot = null;
    /** PHPCfg visibility flags (issue #145). */
    public int $visibility;
    /** Asymmetric set visibility; 0 means same as read (#3165). */
    public int $setVisibility = 0;
    /** Asymmetric get visibility; 0 means same as write (#5059). */
    public int $getVisibility = 0;
    /** Source declared an explicit read modifier before asymmetric set (#15995). */
    public bool $asymmetricExplicitRead = false;
    /**
     * C-level / engine storage only — not in PHP property table
     * (ReflectionAttribute slots, ref->accessible; #22513, #22514).
     */
    public bool $phpInvisible = false;
    /** Lowercase class that declared this property (issue #145). */
    public string $declaringClassLc;

    public function __construct(
        string $name,
        ?Variable $default,
        Variable $prototype,
        bool $readonly = false,
        int $visibility = \PHPCfg\Func::FLAG_PUBLIC,
        string $declaringClassLc = '',
        int $setVisibility = 0,
        int $getVisibility = 0,
        bool $asymmetricExplicitRead = false,
        bool $lazy = false
    ) {
        $this->name = $name;
        $this->default = $default;
        $this->prototype = $prototype;
        $this->readonly = $readonly;
        $this->lazy = $lazy;
        $this->visibility = $visibility;
        $this->declaringClassLc = $declaringClassLc;
        $this->setVisibility = $setVisibility;
        $this->getVisibility = $getVisibility;
        $this->asymmetricExplicitRead = $asymmetricExplicitRead;
    }

    public function hasRuntimeDefaultInit(): bool
    {
        return null !== $this->defaultInitBlock && null !== $this->defaultInitResultSlot;
    }

    /**
     * True when the property declaration carries a type (incl. explicit `mixed`).
     *
     * Compiler stamps untyped prototypes as TYPE_NULL and typed (incl. `mixed`) as
     * TYPE_UNDEFINED (#4240, #22021). Untyped props without an initializer still have
     * an implicit null default in Zend (#22047).
     */
    public function hasDeclaredType(): bool
    {
        $proto = $this->prototype;
        if (Variable::TYPE_UNDEFINED === $proto->type) {
            return true;
        }
        if (null !== $proto->declaredTypeLabel && '' !== $proto->declaredTypeLabel) {
            return true;
        }
        if (null !== $proto->classConstraint && '' !== $proto->classConstraint) {
            return true;
        }

        return $proto->hasDeclaredTypeConstraint();
    }

    public function getVariable(): Variable {
        $var = clone $this->prototype;
        if (
            !is_null($this->default)
            && !$this->hasRuntimeDefaultInit()
            && !$this->lazy
            && !($this->readonly && $this->fromConstructorPromotion)
        ) {
            $var->copyFrom($this->default);
        }

        return $var;
    }


}
