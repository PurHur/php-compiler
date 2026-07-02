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
    /** Lowercase unset-hook method name from property-hooks lowering (#6502), or null. */
    public ?string $unsetHookMethodLc = null;
    /** Virtual hooked property: hooks do not use backing storage (#4687, Zend zend_property_hooks.c). */
    public bool $propertyHookVirtual = false;
    /** Individual readonly property (issue #3149, promoted readonly #3432). */
    public bool $readonly = false;
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
        int $getVisibility = 0
    ) {
        $this->name = $name;
        $this->default = $default;
        $this->prototype = $prototype;
        $this->readonly = $readonly;
        $this->visibility = $visibility;
        $this->declaringClassLc = $declaringClassLc;
        $this->setVisibility = $setVisibility;
        $this->getVisibility = $getVisibility;
    }

    public function hasRuntimeDefaultInit(): bool
    {
        return null !== $this->defaultInitBlock && null !== $this->defaultInitResultSlot;
    }

    public function getVariable(): Variable {
        $var = clone $this->prototype;
        if (
            !is_null($this->default)
            && !$this->hasRuntimeDefaultInit()
            && !($this->readonly && $this->fromConstructorPromotion)
        ) {
            $var->copyFrom($this->default);
        }

        return $var;
    }


}
