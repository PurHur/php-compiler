<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\GcCollectCyclesNativeOpsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** @internal embed GC shutdown — invoke user __destruct when safe (#15852). */
final class phpc_destruct_try_invoke_native extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_destruct_try_invoke_native');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('phpc_destruct_try_invoke_native() is JIT-only (#15852)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_destruct_try_invoke_native() expects 1 argument');
        }
        GcCollectCyclesNativeOpsJit::destructTryInvoke($context, $args[0]);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
