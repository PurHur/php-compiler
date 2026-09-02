<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\GcCollectCyclesNativeOpsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** @internal standalone GC registry mirror — LLVM phpc_gc_count (#36245). */
final class phpc_gc_llvm_registry_count extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_gc_llvm_registry_count');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('phpc_gc_llvm_registry_count() is JIT-only (#36245)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (0 !== \count($args)) {
            throw new \LogicException('phpc_gc_llvm_registry_count() expects 0 arguments');
        }

        return GcCollectCyclesNativeOpsJit::llvmRegistryCount($context);
    }
}
