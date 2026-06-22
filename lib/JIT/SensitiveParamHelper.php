<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\SensitiveParamRuntime;
use PHPCompiler\VM\SensitiveParamSupport;
use PHPLLVM\Value;

/**
 * #[\SensitiveParameter] JIT lowering for debug_backtrace args (issue #3351, #4621, #10394).
 */
final class SensitiveParamHelper
{
    /**
     * Packed call arguments for the enclosing frame, redacting sensitive params.
     */
    public static function buildArgsArray(Context $context, Block $block): Value
    {
        $argsHt = HashTableHelper::alloc($context);
        if ([] === $block->paramNames) {
            return $argsHt;
        }

        $sensitive = $block->paramSensitive;
        $sizeT = $context->getTypeFromString('size_t');
        $index = 0;
        foreach (array_keys($block->paramNames) as $paramIdx) {
            $slot = $context->constantFromInteger($index, 'size_t');
            ++$index;
            if (SensitiveParamSupport::compileTimeParamIsSensitive($sensitive, $paramIdx)) {
                HashTableHelper::setAtIndex($context, $argsHt, $slot, SensitiveParamRuntime::createMarker($context));

                continue;
            }
            $paramName = $block->paramNames[$paramIdx];
            $binding = VarFetchHelper::bindingByName($context, $block, $paramName);
            if (null === $binding) {
                continue;
            }
            HashTableHelper::setAtIndex($context, $argsHt, $slot, $binding);
        }

        return $argsHt;
    }

    public static function ignoreArgsBit(Context $context, ?Variable $optionsArg): Value
    {
        return SensitiveParamRuntime::ignoreArgsBit($context, $optionsArg);
    }
}
