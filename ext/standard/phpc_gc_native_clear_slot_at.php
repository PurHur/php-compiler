<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\GcCollectCyclesNativeOpsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** @internal GC native scan — null a property slot referencing a collected object (#36245). */
final class phpc_gc_native_clear_slot_at extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_gc_native_clear_slot_at');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('phpc_gc_native_clear_slot_at() is JIT-only (#36245)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('phpc_gc_native_clear_slot_at() expects 2 arguments');
        }
        GcCollectCyclesNativeOpsJit::clearSlotAt($context, $args[0], $args[1]);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
