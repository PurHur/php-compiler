<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\DefineRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for define() runtime constant registration (issue #4435). */
final class JitDefine
{
    public static function invoke(Context $context, JITVariable $nameArg, JITVariable $valueArg): Value
    {
        $literal = JitStringArg::compileTimeLiteral($nameArg);
        if (null !== $literal) {
            return define_::invokeLiteral($context, $literal, $valueArg);
        }

        return self::invokeRuntime($context, $nameArg, $valueArg);
    }

    public static function invokeRuntime(
        Context $context,
        JITVariable $nameArg,
        JITVariable $valueArg
    ): Value {
        DefineRuntime::ensureLinked($context);
        $nameStr = JitStringBuiltinArg::lower($context, $nameArg, 'define', 0, 'constant_name');

        return DefineRuntime::emitDefine($context, $nameStr, $valueArg);
    }
}
