<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\JIT\Builtin\StringUtf8Runtime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for mb_strlen() UTF-8 counting (issue #158, #5695).
 */
final class JitMbStrlen
{
    public static function utf8LengthFromPtr(Context $context, Value $strPtr): Value
    {
        StringUtf8Runtime::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_utf8_strlen'),
            $strPtr
        );
    }

    public static function utf8Length(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type && null !== ($arg->compileTimeString ?? null)) {
            return $context->constantFromInteger(
                VmString::utf8CharLength($arg->compileTimeString),
                'int64'
            );
        }

        $str = JitStringBuiltinArg::lower($context, $arg, 'mb_strlen', 0, 'string');

        return self::utf8LengthFromPtr($context, $str);
    }
}
