<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\LocaleLookupRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT helper for locale_lookup() (#32118).
 *
 * Compile-time: fold RFC 4647 lookup through {@see VmLocale::lookup()}.
 * Runtime: NestedJIT {@see LocaleLookupJitHelper::lookupArgv()}.
 * php-src: ext/intl/locale/locale_methods.c
 */
final class JitLocaleLookup
{
    public static function lookup(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'locale_lookup() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'locale_lookup() expects at most 4 arguments, %d given',
                $argc
            ));
        }

        $folded = self::tryFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        if (!self::isArrayOperand($args[0])) {
            JitNativeString::ensureInsertBlock($context);
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                \sprintf(
                    'locale_lookup(): Argument #1 ($languageTag) must be of type array, %s given',
                    JitOperandTypeLabel::givenLabel($context, $args[0])
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'locale_lookup_langtag_te_cont');

            return $context->builder->load($context->constantStringFromString(''));
        }

        $ht = ArrayBuiltinHelper::loadHashTable($context, $args[0]);
        $locale = JitStringBuiltinArg::lowerZparamStr($context, $args[1], 'locale_lookup', 1, 'locale');
        BasicBlockHelper::ensureOpenInsertBlock($context, 'locale_lookup_locale_cont');

        $i64 = $context->getTypeFromString('int64');
        $canonicalize = $i64->constInt(0, false);
        if ($argc >= 3) {
            $canonicalize = $context->builder->zExt(
                JitBoolArg::lowerCoerceZParamBool(
                    $context,
                    $args[2],
                    'locale_lookup',
                    'canonicalize',
                    3
                ),
                $i64
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'locale_lookup_canon_cont');
        }

        $default = $context->builder->load($context->constantStringFromString(''));
        $hasDefault = $i64->constInt(0, false);
        if ($argc >= 4 && JITVariable::TYPE_NULL !== $args[3]->type && !($args[3]->isNullConstant ?? false)) {
            $default = JitStringBuiltinArg::lowerZparamStr(
                $context,
                $args[3],
                'locale_lookup',
                3,
                'defaultLocale'
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'locale_lookup_default_cont');
            $hasDefault = $i64->constInt(1, false);
        }

        return LocaleLookupRuntime::invoke($context, $ht, $locale, $canonicalize, $default, $hasDefault);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function tryFold(Context $context, array $args): ?Value
    {
        $tags = self::compileTimeStringList($args[0]);
        if (null === $tags) {
            return null;
        }
        if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
            return null;
        }
        $locale = $args[1]->compileTimeString ?? JitStringArg::compileTimeLiteral($args[1]);
        if (null === $locale) {
            return null;
        }
        $canonicalize = false;
        if (isset($args[2])) {
            $canonLit = self::compileTimeBool($args[2]);
            if (null === $canonLit) {
                return null;
            }
            $canonicalize = $canonLit;
        }
        $default = null;
        if (isset($args[3]) && JITVariable::TYPE_NULL !== $args[3]->type && !($args[3]->isNullConstant ?? false)) {
            $default = $args[3]->compileTimeString ?? JitStringArg::compileTimeLiteral($args[3]);
            if (null === $default) {
                return null;
            }
        }

        $result = VmLocale::lookup($tags, $locale, $canonicalize, $default);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'locale_lookup_fold');

        return $context->builder->load($context->constantStringFromString($result));
    }

    /** @return list<string>|null */
    private static function compileTimeStringList(JITVariable $arg): ?array
    {
        if (!\is_array($arg->compileTimeArray)) {
            return null;
        }
        $out = [];
        foreach ($arg->compileTimeArray as $value) {
            if (!\is_string($value)) {
                return null;
            }
            $out[] = $value;
        }

        return $out;
    }

    private static function compileTimeBool(JITVariable $arg): ?bool
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return false;
        }
        if (null !== $arg->compileTimeLong) {
            return 0 !== $arg->compileTimeLong;
        }
        $literal = JitStringArg::compileTimeLiteral($arg);
        if (null !== $literal) {
            return '' !== $literal && '0' !== $literal;
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type) {
            $value = $arg->value;
            if (method_exists($value, 'isConstant') && $value->isConstant()
                && method_exists($value, 'getConstantValue')
            ) {
                return 0 !== (int) $value->getConstantValue();
            }
        }

        return null;
    }

    private static function isArrayOperand(JITVariable $arg): bool
    {
        return JITVariable::TYPE_HASHTABLE === $arg->type
            || 0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)
            || JITVariable::TYPE_VALUE === $arg->type;
    }
}
