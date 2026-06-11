<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\JIT\CallUnpackHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCfg\Operand;

/**
 * Compile-time unserialize() options array extraction (#3300, php-src var_unserializer.c).
 */
final class JitUnserializeOptions
{
    /**
     * @return array<string, mixed>|null
     */
    public static function tryCompileTime(
        Context $context,
        JITVariable $arg,
        ?Block $block = null,
        ?Operand $operand = null
    ): ?array {
        if (null !== $block && null !== $operand) {
            $arrayVar = CallUnpackHelper::tryCompileTimeArrayFromOperand($block, $operand);
            if (null !== $arrayVar) {
                return unserialize::parseUnserializeOptionsArray($arrayVar);
            }
        }

        return null;
    }
}
