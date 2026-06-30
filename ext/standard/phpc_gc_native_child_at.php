<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\GcCollectCyclesNativeOpsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** @internal embed GC native scan — read child object ptr at property slot (#13882). */
final class phpc_gc_native_child_at extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_gc_native_child_at');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('phpc_gc_native_child_at() is JIT-only (#13882)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('phpc_gc_native_child_at() expects 2 arguments');
        }

        return GcCollectCyclesNativeOpsJit::childAt($context, $args[0], $args[1]);
    }
}
