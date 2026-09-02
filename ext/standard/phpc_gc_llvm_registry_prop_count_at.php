<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\GcCollectCyclesNativeOpsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** @internal standalone GC registry mirror — LLVM phpc_gc_prop_counts[i] (#36245). */
final class phpc_gc_llvm_registry_prop_count_at extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_gc_llvm_registry_prop_count_at');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('phpc_gc_llvm_registry_prop_count_at() is JIT-only (#36245)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_gc_llvm_registry_prop_count_at() expects 1 argument');
        }

        return GcCollectCyclesNativeOpsJit::llvmRegistryPropCountAt($context, $args[0]);
    }
}
