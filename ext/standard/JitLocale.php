<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Value;

/** LLVM lowering for setlocale()/localeconv() — compile-time libc snapshot (#6133). */
final class JitLocale
{
    public static function setlocale(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2) {
            throw new \LogicException(
                \sprintf('setlocale() expects at least 2 arguments, %d given', $argc)
            );
        }

        $category = self::compileTimeInt($args[0], 'setlocale(): Argument #1 ($category)');
        $localeArgs = self::compileTimeLocaleArgs(\array_slice($args, 1));
        $result = VmLocale::setlocale($category, $localeArgs);

        return self::stringOrFalse($context, $result);
    }

    public static function localeconv(Context $context, JITVariable ...$args): Value
    {
        if ([] !== $args) {
            throw new \LogicException(
                \sprintf('localeconv() expects exactly 0 arguments, %d given', \count($args))
            );
        }

        return self::wrapHashTable($context, self::emitHashTablePtr($context, VmLocale::localeconv()));
    }

    /** @param list<JITVariable> $args */
    private static function compileTimeLocaleArgs(array $args): array
    {
        if ([] === $args) {
            return [];
        }

        $first = $args[0];
        if (JITVariable::TYPE_HASHTABLE === $first->type || ($first->type & JITVariable::IS_NATIVE_ARRAY)) {
            throw new \LogicException('setlocale() array locales are not supported in this compiler build');
        }

        $out = [];
        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
                $var = new VMVariable();
                $var->null();
                $out[] = $var;
                continue;
            }
            if (null !== ($arg->compileTimeString ?? null)) {
                if ('0' === $arg->compileTimeString) {
                    $var = new VMVariable();
                    $var->null();
                    $out[] = $var;
                    continue;
                }
                $var = new VMVariable();
                $var->string($arg->compileTimeString);
                $out[] = $var;
                continue;
            }
            if (JITVariable::TYPE_STRING === $arg->type && null !== $arg->value) {
                throw new \LogicException('setlocale() requires compile-time locale strings in this compiler build');
            }
            throw new \LogicException(
                'setlocale(): Argument #'.($i + 2).' ($locales) must be a compile-time string or null in this compiler build'
            );
        }

        return $out;
    }

    private static function compileTimeInt(JITVariable $arg, string $label): int
    {
        if (null !== ($arg->compileTimeInteger ?? null)) {
            return (int) $arg->compileTimeInteger;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            throw new \LogicException($label.' must be a compile-time integer in this compiler build');
        }

        throw new \LogicException($label.' must be an integer in this compiler build');
    }

    /** @param string|false $result */
    private static function stringOrFalse(Context $context, string|false $result): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (false === $result) {
            $context->builder->call(
                $context->lookupFunction('__value__writeBool'),
                $ptr,
                $context->getTypeFromString('int1')->constInt(0, false)
            );

            return $ptr;
        }

        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($result), false),
            $context->builder->pointerCast($context->constantFromString($result), $charPtr)
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $str
        );

        return $ptr;
    }

    private static function emitHashTablePtr(Context $context, HashTable $table): Value
    {
        $ht = HashTableHelper::alloc($context);
        foreach ($table->iterateKeyed(true) as [$keyVar, $valueVar]) {
            if (VMVariable::TYPE_STRING !== $keyVar->type) {
                continue;
            }
            $keyStr = $context->builder->load(
                $context->constantStringFromString($keyVar->toString())
            );
            self::storeVmVariable($context, $ht, $keyStr, $valueVar);
        }

        return $ht;
    }

    private static function wrapHashTable(Context $context, Value $ht): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }

    private static function storeVmVariable(
        Context $context,
        Value $ht,
        Value $keyStr,
        VMVariable $value
    ): void {
        $resolved = $value->resolveIndirect();
        switch ($resolved->type) {
            case VMVariable::TYPE_INTEGER:
                $jit = new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_LONG,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('int64')->constInt($resolved->toInt(), false)
                );
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);

                return;
            case VMVariable::TYPE_STRING:
                $jit = new JITVariable(
                    $context,
                    JITVariable::TYPE_STRING,
                    JITVariable::KIND_VALUE,
                    $context->builder->load($context->constantStringFromString($resolved->toString()))
                );
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);

                return;
            default:
                throw new \LogicException(
                    'localeconv() unsupported field type: '
                    .VMVariable::getStringType($resolved->type)
                );
        }
    }
}
