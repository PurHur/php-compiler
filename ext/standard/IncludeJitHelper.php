<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCompiler\Block;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPCompiler\VM\VmInclude;

/**
 * Compile-time include path resolution for JIT/AOT (#10063, php-in-PHP).
 *
 * VM SSOT: {@see VmInclude} — self-host skip guards, spine bundle policy
 * php-src: Zend/zend_compile.c — compile-time literal includes
 */
final class IncludeJitHelper
{
    public static function shouldStubM3SidecarHostNonLiteralInclude(Block $callerBlock): bool
    {
        return VmInclude::shouldStubM3SidecarHostNonLiteralInclude($callerBlock->scriptPath());
    }

    public static function shouldSkipSelfHostSpineCliInclude(string $path): bool
    {
        return VmInclude::shouldSkipSelfHostSpineCliInclude($path);
    }

    public static function skippedSelfHostIncludeReturnInt(): int
    {
        return VmInclude::SKIPPED_SELFHOST_INCLUDE_RETURN;
    }

    public static function resolveLiteralPath(
        Block $block,
        int $pathSlot,
        Operand $pathOperand,
        Context $context
    ): ?string {
        if ($pathOperand instanceof Literal && is_string($pathOperand->value)) {
            return $pathOperand->value;
        }
        if (isset($block->constants[$pathSlot])) {
            $constant = $block->constants[$pathSlot];
            if ($constant instanceof VmVariable && VmVariable::TYPE_STRING === $constant->type) {
                return $constant->toString();
            }
        }
        if ($context->hasVariableOp($pathOperand)) {
            return $context->getVariableFromOp($pathOperand)->compileTimeString;
        }

        return null;
    }
}
