<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\GcCollectCyclesNativeOpsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** @internal embed GC native scan — read native __object__ refcount (#13882). */
final class phpc_gc_native_object_refcount extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_gc_native_object_refcount');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('phpc_gc_native_object_refcount() is JIT-only (#13882)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_gc_native_object_refcount() expects 1 argument');
        }

        return GcCollectCyclesNativeOpsJit::objectRefcount($context, $args[0]);
    }
}
