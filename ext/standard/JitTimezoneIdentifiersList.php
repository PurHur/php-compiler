<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeZoneSupport;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for timezone_identifiers_list() — compile-time list baking (#3504).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_identifiers_list)
 */
final class JitTimezoneIdentifiersList
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        return self::invokeNamed($context, 'timezone_identifiers_list', ...$args);
    }

    /**
     * Shared bake path for procedural + DateTimeZone::listIdentifiers (#29735, #29844).
     */
    public static function invokeNamed(Context $context, string $function, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 2) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                \sprintf('%s() expects at most 2 arguments, %d given', $function, $argc)
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $timezoneGroup = DateTimeZoneSupport::GROUP_ALL;
        $countryCode = null;
        if ($argc >= 1) {
            // int $timezoneGroup — null TypeError under caller strict_types (#29844).
            if (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant) {
                JitInternalStrictArg::requireInt($context, $args[0], $function, 'timezoneGroup', 1);
                $timezoneGroup = 0;
            } else {
                $group = self::tryCompileTimeInt($context, $args[0], 'timezoneGroup');
                if (null === $group) {
                    throw new \LogicException(
                        $function.'() requires compile-time timezoneGroup in this compiler build (issue #3504)'
                    );
                }
                $timezoneGroup = $group;
            }
        }
        if ($argc >= 2) {
            $countryCode = self::tryCompileTimeNullableString($context, $args[1], 'countryCode');
            if (false === $countryCode) {
                throw new \LogicException(
                    $function.'() requires compile-time countryCode in this compiler build (issue #3504)'
                );
            }
        }

        $identifiers = VmDateTimeNative::timezoneIdentifiersList($timezoneGroup, $countryCode);
        $htVar = HashTableHelper::variableFromVmHashTable(
            $context,
            VmFs::stringListToArray($identifiers)
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $context->helper->loadValue($htVar)
        );

        return $ptr;
    }

    private static function tryCompileTimeInt(
        Context $context,
        JITVariable $var,
        string $paramName
    ): ?int {
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

    /**
     * @return string|null|false null = ok null, string = ok string, false = not compile-time
     */
    private static function tryCompileTimeNullableString(
        Context $context,
        JITVariable $var,
        string $paramName
    ): string|null|false {
        if (JITVariable::TYPE_NULL === $var->type) {
            return null;
        }
        if (JITVariable::TYPE_VALUE === $var->type) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $var);
            $map = $context->structFieldMap['__value__'];
            $typeByte = $context->builder->load(
                $context->builder->structGep($valuePtr, $map['type'])
            );
            $i8 = $context->getTypeFromString('int8');
            $isNull = $context->builder->icmp(
                \PHPLLVM\Builder::INT_EQ,
                $typeByte,
                $i8->constInt(VmVariable::TYPE_NULL, false)
            );
            if ($isNull) {
                return null;
            }
        }
        $literal = JitStringArg::compileTimeLiteral($var);
        if (null !== $literal) {
            return $literal;
        }

        return false;
    }
}
