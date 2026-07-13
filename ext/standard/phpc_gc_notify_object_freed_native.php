<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\GcCollectCyclesNativeOpsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** @internal embed GC — clear weak refs on object free (#18660). */
final class phpc_gc_notify_object_freed_native extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_gc_notify_object_freed_native');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('phpc_gc_notify_object_freed_native() is JIT-only (#18660)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_gc_notify_object_freed_native() expects 1 argument');
        }
        GcCollectCyclesNativeOpsJit::notifyObjectFreed($context, $args[0]);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
