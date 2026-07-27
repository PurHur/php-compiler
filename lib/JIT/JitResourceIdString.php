<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\VmResourceIdString;
use PHPCfg\Operand;
use PHPLLVM\Value;

/**
 * JIT trampoline for resource handle string formatting (#9976).
 *
 * SSOT: {@see \PHPCompiler\VM\VmResourceIdString}
 */
final class JitResourceIdString
{
    public static function formatNativeLong(
        Context $context,
        Value $longVal,
        ?Operand $sourceOperand = null
    ): Value
    {
        return VmResourceIdString::formatNativeLong($context, $longVal, $sourceOperand);
    }
}
