<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmMemory;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * Compile-time MemoryUsage lowering for memory_get_*() (#7247).
 */
final class MemoryUsageJit
{
    public static function compileTimeUsageBool(Context $context, JITVariable $arg): ?bool
    {
        if (null === $arg->compileTimeConstantName || null === $context->runtime->vmContext) {
            return null;
        }
        $phpVar = $context->runtime->vmContext->constantFetch($arg->compileTimeConstantName);
        if (null === $phpVar) {
            return null;
        }
        $fromEnum = VmMemory::tryMemoryUsageBool($phpVar);
        if (null !== $fromEnum) {
            return $fromEnum;
        }
        if (\PHPCompiler\VM\Variable::TYPE_BOOLEAN === $phpVar->type) {
            return $phpVar->toBool();
        }

        return null;
    }
}
