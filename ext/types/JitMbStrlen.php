<?php

declare(strict_types=1);

namespace PHPCompiler\ext\types;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for mb_strlen() UTF-8 counting (issue #158).
 */
final class JitMbStrlen
{
    public static function utf8Length(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING !== $arg->type) {
            throw new \LogicException('mb_strlen() only supports strings in this compiler build');
        }

        $literal = $arg->compileTimeString ?? null;
        if (null !== $literal) {
            return $context->constantFromInteger(
                VmString::utf8CharLength($literal),
                'int64'
            );
        }

        $strPtr = $context->helper->loadValue($arg);

        return $context->builder->call(
            $context->lookupFunction('__compiler_utf8_strlen'),
            $strPtr
        );
    }
}
