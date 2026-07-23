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
        $index = 0;
        foreach (array_keys($block->paramNames) as $paramIdx) {
            $paramName = $block->paramNames[$paramIdx];
            if ('this' === $paramName) {
                continue;
            }
            $slot = $context->constantFromInteger($index, 'size_t');
            ++$index;
            $binding = VarFetchHelper::bindingByName($context, $block, $paramName);
            if (SensitiveParamSupport::compileTimeParamIsSensitive($sensitive, $paramIdx)) {
                HashTableHelper::setAtIndex($context, $argsHt, $slot, SensitiveParamRuntime::wrapValue($context, $binding));

                continue;
            }
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
