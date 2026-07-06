<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\GcCollectCyclesNativeOpsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** @internal embed GC shutdown — release object storage after destructors (#15852). */
final class phpc_object_release_storage_native extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_object_release_storage_native');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('phpc_object_release_storage_native() is JIT-only (#15852)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_object_release_storage_native() expects 1 argument');
        }
        GcCollectCyclesNativeOpsJit::releaseObjectStorage($context, $args[0]);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
