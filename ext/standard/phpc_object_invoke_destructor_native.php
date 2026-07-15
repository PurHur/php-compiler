<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\GcCollectCyclesNativeOpsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** @internal embed GC — invoke user __destruct via LLVM object dispatch (#18660). */
final class phpc_object_invoke_destructor_native extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_object_invoke_destructor_native');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('phpc_object_invoke_destructor_native() is JIT-only (#18660)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_object_invoke_destructor_native() expects 1 argument');
        }
        GcCollectCyclesNativeOpsJit::invokeDestructor($context, $args[0]);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
