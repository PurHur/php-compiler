<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Variable;

use PHPLLVM\Value;

class Vararg implements Call {

    public Value $function;
    public string $name;
    public int $numRequiredArgs;

    public function __construct(Value $function, string $name, int $numRequiredArgs) {
        $this->function = $function;
        $this->name = $name;
        $this->numRequiredArgs = $numRequiredArgs;
    }

    public function call(Context $context, Variable ... $args): Value {
        $argValues = [];
        $total = count($args);
        for ($index = 0; $index < $total; $index++) {
            $argValues[] = $context->helper->loadValue($args[$index]);
        }
        $required = array_slice($argValues, 0, $this->numRequiredArgs);
        $varargs = array_slice($argValues, $this->numRequiredArgs);
        return $context->builder->call(
            $this->function,
            ...$required,
            count($varargs),
            ...$varargs
        );
    }

}