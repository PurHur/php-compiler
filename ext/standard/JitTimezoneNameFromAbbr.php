<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for timezone_name_from_abbr() (#10957).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_name_from_abbr)
 */
final class JitTimezoneNameFromAbbr
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                \sprintf('timezone_name_from_abbr() expects between 1 and 3 arguments, %d given', $argc)
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $abbrLiteral = JitStringArg::compileTimeLiteral($args[0]);
        if (null === $abbrLiteral) {
            throw new \LogicException(
                'timezone_name_from_abbr() requires compile-time abbr in this compiler build (issue #10957)'
            );
        }

        $gmtoffset = -1;
        $isdst = -1;
        if ($argc >= 2) {
            $offsetLiteral = self::tryCompileTimeInt($context, $args[1]);
            if (null === $offsetLiteral) {
                throw new \LogicException(
                    'timezone_name_from_abbr() requires compile-time gmtoffset in this compiler build (issue #10957)'
                );
            }
            $gmtoffset = $offsetLiteral;
        }
        if ($argc >= 3) {
            $dstLiteral = self::tryCompileTimeInt($context, $args[2]);
            if (null === $dstLiteral) {
                throw new \LogicException(
                    'timezone_name_from_abbr() requires compile-time isdst in this compiler build (issue #10957)'
                );
            }
            $isdst = $dstLiteral;
        }

        return self::writeResult(
            $context,
            VmDateTimeNative::timezoneNameFromAbbr($abbrLiteral, $gmtoffset, $isdst)
        );
    }

    /**
     * @param string|false $tzid
     */
    private static function writeResult(Context $context, string|false $tzid): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (false === $tzid) {
            $context->builder->call(
                $context->lookupFunction('__value__writeBool'),
                $ptr,
                $context->getTypeFromString('int1')->constInt(0, false)
            );
        } else {
            $owned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load($context->constantStringFromString($tzid))
            );
            $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);
        }

        return $ptr;
    }

    private static function tryCompileTimeInt(Context $context, JITVariable $var): ?int
    {
        if (JITVariable::KIND_VALUE !== $var->kind) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (JITVariable::TYPE_NATIVE_LONG === $var->type
            && null !== $lib->LLVMIsAConstantInt($var->value->value)
        ) {
            return (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
        }
        $literal = $var->compileTimeString ?? null;
        if (null !== $literal && is_numeric($literal) && ((string) (int) $literal) === $literal) {
            return (int) $literal;
        }
        $name = $var->compileTimeConstantName ?? null;
        if (null === $name || null === $context->runtime->vmContext) {
            return null;
        }
        $phpVar = $context->runtime->vmContext->constantFetch($name);
        if (null !== $phpVar && VmVariable::TYPE_INTEGER === $phpVar->type) {
            return $phpVar->toInt();
        }

        return null;
    }
}
