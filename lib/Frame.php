<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

class Frame {
    public ?Block $block;
    public ?Frame $parent;
    public int $pos = 0;
    /**
     * @var Variable[] $scope
     */
    public array $scope;

    public ?Func $call = null;
    public array $callArgs = [];
    /** @var list<array{0: string, 1?: mixed, 2?: Variable}> */
    public array $callArgEntries = [];
    public array $calledArgs = [];
    public ?Variable $returnVar = null;
    public ?Handler $handler = null;

    /** When true, finishing this frame resumes the caller instead of ending execution. */
    public bool $ephemeral = false;

    /** Absolute path of the script this frame executes (issue #707). */
    public string $scriptPath = '';

    /** Call-site line for the pending FUNCCALL (issue #4482). */
    public int $callSiteLine = 0;

    /** Return-statement line for return-type fatals (#11381). */
    public int $returnSiteLine = 0;

    /** VM context for nested builtin calls (set when invoking Internal handlers). */
    public ?Context $vmContext = null;

    /** Late-static-bind class for the active call (runtime class name, issue #1231). */
    public ?string $calledClass = null;

    /**
     * Declaring class (self/parent) for eval/include {main} — distinct from {@see $calledClass} LSB (#31912).
     *
     * php-src: execute_data->func->common.scope vs called_scope.
     */
    public ?string $scopeClass = null;

    /** Class used for the pending static call (set by STATICCALL_INIT / initStaticCallable). */
    public ?string $staticCallClass = null;

    /** Qualified builtin callee for named-arg binding (e.g. DateTime::createFromFormat; #11785). */
    public ?string $builtinCalleeQualifiedMethod = null;

    /** Active generator while executing a generator function body (issue #167). */
    public ?VM\GeneratorState $generatorState = null;

    /** Active fiber while executing a fiber callback (issue #3130). */
    public ?VM\FiberState $fiberState = null;

    /** Pending closure call: captures bound when the callee frame is entered (issue #72). */
    public ?VM\ClosureState $closureCall = null;

    /** Scope slot of the callable operand for the pending FUNCCALL (issue #4872). */
    public ?int $closureCallableSlot = null;

    /** Closure pending __invoke / FUNCCALL (survives until callee entry; issue #4872). */
    public ?VM\ClosureState $pendingClosureInvoke = null;

    /**
     * When set, writes to this instance property name use backing storage (inside a hook body, #3145).
     */
    public ?string $propertyHookRawProperty = null;

    /** Original method name when dispatching via __call / __callStatic (#146, #3273). */
    public ?string $magicCallMethodName = null;

    /** Set when TYPE_YIELD suspends; runFrames returns GENERATOR_YIELD. */
    public bool $generatorYield = false;

    /** Exception object bound for bare `throw;` in this catch body (#3508). */
    public ?Variable $activeCatchException = null;

    /** Scope slot for `catch (Throwable $e)` — suppress false undefined-var reads (#10358). */
    public ?int $catchVarSlot = null;

    /** Set when Fiber::suspend() suspends; runFrames returns FIBER_SUSPEND. */
    public bool $fiberSuspend = false;

    /** Skip one ECHO after builtin string coercion throw was caught (#4284). */
    public bool $suppressNextEcho = false;

    /**
     * Merge block for an in-flight guarded list destructuring assign (#13932).
     * While set, VM defers dead-temp release so nested dim-fetch temps stay alive.
     */
    public ?Block $listUnpackAssignMergeBlock = null;

    /**
     * Foreach iterator container cache keyed by scope slot.
     * php-cfg SSA temps may alias (issue #1885); ITER_* must not rely on rereading the slot.
     *
     * @var array<int, Variable>
     */
    public array $iterators = [];

    /**
     * Runtime locals materialized by variable variables when the name is absent from compile-time scope (#3801).
     *
     * @var array<string, Variable>
     */
    public array $dynamicLocals = [];

    /** Scope slots that received a runtime value (assign/param/static/inc-dec write, #6800). */
    public array $initializedSlots = [];

    /**
     * Saved FUNCCALL_INIT state while TYPE_NEW runs for an inline `new` call arg (#15217).
     *
     * @var array{call: Func, callArgs: list<Variable>, callArgEntries: list<array{0: string, 1?: mixed, 2?: Variable}>, callSiteLine: int, builtinCalleeQualifiedMethod: ?string}|null
     */
    public ?array $pendingOutboundCallRestore = null;

    public function __construct(?Handler $handler, ?Block $block, ?Frame $parent, Variable ...$scope) {
        $this->handler = $handler;
        $this->block = $block;
        if (is_null($handler) && is_null($block)) {
            throw new \LogicException("Both handler and block cannot be null, one must be non-null");
        }
        $this->parent = $parent;
        $this->scope = $scope;
    }

    public function hasHandler(): bool {
        return !is_null($this->handler);
    }
}