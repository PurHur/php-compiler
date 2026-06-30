<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\GcCollectCyclesNativeOpsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** @internal embed GC native scan — free unmarked native registry object (#13882). */
final class phpc_gc_native_free_object extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_gc_native_free_object');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('phpc_gc_native_free_object() is JIT-only (#13882)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_gc_native_free_object() expects 1 argument');
        }
        GcCollectCyclesNativeOpsJit::freeObject($context, $args[0]);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
