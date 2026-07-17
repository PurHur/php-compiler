<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Builtin\MbStrcut;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for mb_strcut() — MbStrcutJitHelper in-module (#4573). */
final class JitMbStrcut
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('mb_strcut() expects 2 to 4 arguments in this compiler build');
        }

        $strLit = $args[0]->compileTimeString ?? null;
        $fromLit = self::compileTimeInt($context, $args[1]);
        $lenLit = $argc >= 3 ? self::compileTimeOptionalInt($context, $args[2]) : -1;
        $encLit = $argc >= 4 ? ($args[3]->compileTimeString ?? null) : 'UTF-8';
        if (null !== $strLit && null !== $fromLit && null !== $lenLit && null !== $encLit) {
            return self::materializeString(
                $context,
                VmMbstring::strcut($strLit, $fromLit, $lenLit < 0 ? null : $lenLit, $encLit)
            );
        }

        // Z_PARAM_STR — null TypeError on 8.4 forward profile (#20225).
        $str = JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'mb_strcut', 0, 'string');
        $from = JitStrictIntArg::lower($context, $args[1], 'mb_strcut', 2, 'start');
        $i64 = $context->getTypeFromString('int64');
        if ($argc >= 3) {
            if (JITVariable::TYPE_NULL === $args[2]->type) {
                $length = $i64->constInt(-1, true);
            } else {
                $length = JitStrictIntArg::lower($context, $args[2], 'mb_strcut', 3, 'length');
            }
        } else {
            $length = $i64->constInt(-1, true);
        }
        if ($argc >= 4) {
            if (JITVariable::TYPE_STRING !== $args[3]->type) {
                throw new \LogicException('mb_strcut() encoding must be a string literal in this compiler build');
            }
            $encoding = $args[3]->compileTimeString ?? 'UTF-8';
        } else {
            $encoding = 'UTF-8';
        }
        if ('UTF-8' !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding) {
            throw new \LogicException(
                'mb_strcut() JIT only supports UTF-8, ASCII, or 8BIT encoding literals in this compiler build'
            );
        }

        MbStrcut::ensureLinked($context);
        $encPtr = $context->builder->load($context->constantStringFromString($encoding));
        $resultStr = $context->builder->call(
            MbStrcut::helperFunction($context),
            $str,
            $from,
            $length,
            $encPtr
        );
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $resultStr);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    private static function materializeString(Context $context, string $str): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->builder->load($context->constantStringFromString($str))
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    private static function compileTimeInt(Context $context, JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type || JITVariable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (null === $lib->LLVMIsAConstantInt($arg->value->value)) {
            return null;
        }

        return (int) $lib->LLVMConstIntGetSExtValue($arg->value->value);
    }

    private static function compileTimeOptionalInt(Context $context, JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NULL === $arg->type) {
            return -1;
        }

        return self::compileTimeInt($context, $arg);
    }
}
