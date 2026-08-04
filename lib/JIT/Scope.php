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
    /** Call-site class id for standalone `new static()` / `: static` (#4792). */
    public ?int $lateStaticCallClassId = null;
    /** Using class when lowering a trait method body merged onto a class (#18878). */
    public string $traitComposingClassName = '';
    public \SplObjectStorage $blockStorage;
    /** LLVM entry BB per CFG block; stable when includes retarget {@see $blockStorage} (#866, #878). */
    public \SplObjectStorage $blockEntryStorage;
    public \SplObjectStorage $variables;
    public ?Call $toCall = null;
    public array $args = [];

    /** Original method name when dispatching via __call / __callStatic (#146, #4022). */
    public ?string $magicCallMethodName = null;

    /**
     * True when {@see $magicCallMethodName} was set by `__callStatic` (no `$this` prefix).
     * Distinguishes static magic from instance `__call` when user args are Variables (#27517).
     */
    public bool $magicCallIsStatic = false;

    /** Resume LLVM symbol when calling a user generator (#3074). */
    public ?string $generatorResumeCallee = null;

    /** Parallel to {@see $args}: CFG operands for the current call (issue #3161). */
    public array $argOperands = [];

    /** `new` without __construct: FUNCCALL_EXEC_RETURN must not clobber the object slot (#8308). */
    public bool $preserveNewResultOnNullCall = false;

    /**
     * Fiber::suspend() was short-circuited in a resume function — next FUNCCALL_EXEC_RETURN
     * must load {@see __fiber_state__}::resume_argument (#26801, Zend/zend_fibers.c).
     */
    public bool $fiberSuspendResultPending = false;

    public function __construct() {
        $this->blockStorage = new \SplObjectStorage;
        $this->blockEntryStorage = new \SplObjectStorage;
        $this->variables = new \SplObjectStorage;
    }
}
