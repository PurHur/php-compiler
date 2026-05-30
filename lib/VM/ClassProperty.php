<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\VM;

class ClassProperty {

    public string $name;
    public ?Variable $default;
    public Variable $prototype;
    /** Lowercase set-hook method name from property-hooks lowering (#3145), or null. */
    public ?string $setHookMethodLc = null;
    /** Lowercase get-hook method name from property-hooks lowering (#3145), or null. */
    public ?string $getHookMethodLc = null;
    /** Individual readonly property (issue #3149, promoted readonly #3432). */
    public bool $readonly = false;

    public function __construct(string $name, ?Variable $default, Variable $prototype, bool $readonly = false) {
        $this->name = $name;
        $this->default = $default;
        $this->prototype = $prototype;
        $this->readonly = $readonly;
    }

    public function getVariable(): Variable {
        $var = clone $this->prototype;
        if (!is_null($this->default)) {
            $var->copyFrom($this->default);
        }

        return $var;
    }


}
