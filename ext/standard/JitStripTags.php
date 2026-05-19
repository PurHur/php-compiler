<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for strip_tags() (mirrors VmString::stripTags).
 */
final class JitStripTags
{
    public static function stripTags(Context $context, JITVariable $input, ?JITVariable $allowed = null): Value
    {
        $inputLiteral = $input->compileTimeString ?? null;
        if (null !== $inputLiteral) {
            $allowedLiteral = null;
            if (null !== $allowed) {
                if (JITVariable::TYPE_STRING === $allowed->type) {
                    $allowedLiteral = $allowed->compileTimeString ?? '';
                } elseif (JITVariable::TYPE_VALUE !== $allowed->type) {
                    throw new \LogicException(
                        'strip_tags() allowed_tags must be a string or null in this compiler build'
                    );
                }
            }

            return $context->builder->load(
                $context->constantStringFromString(VmString::stripTags($inputLiteral, $allowedLiteral))
            );
        }

        $inPtr = self::jitStringArg($context, $input);
        $allowPtr = self::jitAllowedArg($context, $allowed);

        return $context->builder->call(
            $context->lookupFunction('__compiler_strip_tags'),
            $inPtr,
            $allowPtr
        );
    }

    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $arg->value
            );
        }

        throw new \LogicException('strip_tags() only supports strings in this compiler build');
    }

    private static function jitAllowedArg(Context $context, ?JITVariable $allowed): Value
    {
        if (null === $allowed) {
            return $context->builder->load($context->constantStringFromString(''));
        }
        if (JITVariable::TYPE_STRING === $allowed->type) {
            return $context->helper->loadValue($allowed);
        }
        if (JITVariable::TYPE_VALUE === $allowed->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $allowed->value
            );
        }

        throw new \LogicException('strip_tags() allowed_tags must be a string or null in this compiler build');
    }
}
