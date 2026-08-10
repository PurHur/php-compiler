<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** LLVM lowering for timezone_transitions_get() / DateTimeZone::getTransitions() (#6041, #26799). */
final class JitTimezoneTransitionsGet
{
    private const TYPE_ERROR =
        'timezone_transitions_get(): Argument #1 ($object) must be of type DateTimeZone, %s given';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(
                \sprintf('timezone_transitions_get() expects between 1 and 3 arguments, %d given', $argc)
            );
        }

        return self::lower($context, 'timezone_transitions_get', ...$args);
    }

    /** DateTimeZone::getTransitions($this, …) — same ABI as procedural (#26799). */
    public static function invokeMethod(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(
                \sprintf('DateTimeZone::getTransitions() expects at most 2 arguments, %d given', max(0, $argc - 1))
            );
        }

        return self::lower($context, 'DateTimeZone::getTransitions', ...$args);
    }

    private static function lower(Context $context, string $function, JITVariable ...$args): Value
    {
        $zoneName = self::tryCompileTimeZoneName($context, $args[0]);
        $begin = self::tryCompileTimeInt($context, $args[1] ?? null) ?? \PHP_INT_MIN;
        $end = self::tryCompileTimeInt($context, $args[2] ?? null) ?? \PHP_INT_MAX;

        if (null === $zoneName) {
            throw new \LogicException(
                $function.'() requires a compile-time DateTimeZone name in this compiler build (#26799)'
            );
        }

        $transitions = VmDateTimeNative::exportTimezoneTransitions($zoneName, $begin, $end);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (false === $transitions) {
            JitValueBox::writeBool(
                $context,
                $slot,
                $context->getTypeFromString('int1')->constInt(0, false)
            );

            return $ptr;
        }

        $ht = self::materializeTransitions($context, $transitions);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $context->helper->loadValue($ht)
        );

        return $ptr;
    }

    /**
     * @param list<array{ts: int, time: string, offset: int, isdst: bool, abbr: string}> $transitions
     */
    private static function materializeTransitions(Context $context, array $transitions): JITVariable
    {
        $outer = new HashTable();
        foreach ($transitions as $index => $transition) {
            $row = new HashTable();
            foreach ($transition as $key => $value) {
                $cell = new Variable();
                if (\is_int($value)) {
                    $cell->int($value);
                } elseif (\is_bool($value)) {
                    $cell->bool($value);
                } else {
                    $cell->string((string) $value);
                }
                $row->addNew((string) $key, $cell);
            }
            $entry = new Variable();
            $entry->array($row);
            $outer->addNew((string) $index, $entry);
        }

        return HashTableHelper::variableFromVmHashTable($context, $outer);
    }

    private static function tryCompileTimeZoneName(Context $context, JITVariable $arg): ?string
    {
        // Dedicated stamp from DateTimeZone::__construct / local-name map (#29732 / #29734).
        if (null !== $arg->compileTimeTimezoneName && '' !== $arg->compileTimeTimezoneName) {
            return $arg->compileTimeTimezoneName;
        }
        // Ignore New_ class-name collision on compileTimeString.
        if (
            null !== $arg->compileTimeString
            && '' !== $arg->compileTimeString
            && 0 !== strcasecmp($arg->compileTimeString, 'DateTimeZone')
        ) {
            return $arg->compileTimeString;
        }

        $literal = JitStringBuiltinArg::compileTimeLiteral($arg);
        if (null !== $literal && '' !== $literal) {
            return $literal;
        }

        if (JITVariable::TYPE_OBJECT === $arg->type) {
            /** @var \PHPCompiler\JIT\Builtin\Type\Object_ $object */
            $object = $context->type->object;
            $prop = $object->propertyFetch($arg->value, 'DateTimeZone', DateTimeSupport::TZ_NAME_PROPERTY);
            if (JITVariable::TYPE_STRING === $prop->type && null !== ($prop->compileTimeString ?? null)) {
                return $prop->compileTimeString;
            }
        }

        return null;
    }

    private static function tryCompileTimeInt(Context $context, ?JITVariable $arg): ?int
    {
        if (null === $arg) {
            return null;
        }
        if (null !== $arg->compileTimeLong) {
            return (int) $arg->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($arg->value->value)) {
                return (int) $lib->LLVMConstIntGetSExtValue($arg->value->value);
            }
        }

        return null;
    }
}
