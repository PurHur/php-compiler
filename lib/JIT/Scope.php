<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\JIT;

class Scope {
    public int $classId = 0;
    public string $className = '';
    /** True while lowering a `readonly class` body (#4082). */
    public bool $classIsReadonly = false;
    /** Runtime called class for late static binding (issue #1231). */
    public string $calledClassName = '';
    public \SplObjectStorage $blockStorage;
    public \SplObjectStorage $variables;
    public ?Call $toCall = null;
    public array $args = [];

    /** Original method name when dispatching via __call (#146, #4022). */
    public ?string $magicCallMethodName = null;

    /** Resume LLVM symbol when calling a user generator (#3074). */
    public ?string $generatorResumeCallee = null;

    /** Parallel to {@see $args}: CFG operands for the current call (issue #3161). */
    public array $argOperands = [];

    public function __construct() {
        $this->blockStorage = new \SplObjectStorage;
        $this->variables = new \SplObjectStorage;
    }
}
