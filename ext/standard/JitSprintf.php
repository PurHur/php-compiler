<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for sprintf() (%s, %d, %f, %%).
 */
final class JitSprintf
{
    public static function formatWithFmt(Context $context, Value $fmt, JITVariable ...$args): Value
    {
        $wrapped = [new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $fmt)];

        return self::format($context, ...array_merge($wrapped, $args));
    }

    public static function format(Context $context, JITVariable ...$args): Value
    {
        // User-standalone init skips StringFormat::ensureLinked (#13571) —
        // without a body the ABI symbols die at link with undefined
        // __compiler_sprintf (#15642). Link on first call-site lowering,
        // mid-compile like every other nested helper. NOT during helper-unit
        // emission: the unit only needs the declaration (the consuming script
        // provides the body), and the emitter guards the cache off, so the
        // hook would drag a full nested corpus compile into every unit.
        if ('1' !== getenv('PHP_COMPILER_HELPER_RUNTIME_EMITTING')) {
            \PHPCompiler\JIT\Builtin\StringFormat::implementIfDeclared($context, true);
        }
        $argc = \count($args);
        if ($argc < 1) {
            throw new \ArgumentCountError(\sprintf(
                'sprintf() expects at least 1 argument, %d given',
                $argc
            ));
        }
        $fmt = JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'sprintf', 0, 'format');
        $numArgs = $argc - 1;
        if (0 === $numArgs) {
            $nullArgv = $context->builder->pointerCast(
                $context->getTypeFromString('int64')->constInt(0, false),
                $context->getTypeFromString('__value__*')
            );

            return $context->builder->call(
                $context->lookupFunction('__compiler_sprintf'),
                $fmt,
                $context->getTypeFromString('int64')->constInt(0, false),
                $nullArgv
            );
        }

        $valueTy = $context->getTypeFromString('__value__');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $elemSize = $context->builder->ptrToInt(
            $context->builder->gep(
                $valueTy->pointerType(0)->constNull(),
                $i32->constInt(1, false)
            ),
            $sizeT
        );
        $argvCountSize = $context->builder->intCast(
            $i64->constInt($numArgs, false),
            $sizeT
        );
        $argvBytes = $context->builder->mul($elemSize, $argvCountSize);
        $argvRaw = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $argvBytes
        );
        $argvPtr = $context->builder->pointerCast(
            $argvRaw,
            $context->getTypeFromString('__value__*')
        );
        for ($i = 0; $i < $numArgs; ++$i) {
            $slot = $context->builder->inBoundsGEP(
                $argvPtr,
                $i64->constInt($i, false)
            );
            self::writeArg($context, $slot, $args[$i + 1]);
        }
        $argcVal = $i64->constInt($numArgs, false);
        $result = $context->builder->call(
            $context->lookupFunction('__compiler_sprintf'),
            $fmt,
            $argcVal,
            $argvPtr
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $argvRaw);

        return $result;
    }

    public static function writeArg(Context $context, Value $slot, JITVariable $arg): void
    {
        $ptr = JitValueBox::pointer($context, $slot);
        // Inference can declare a native type while the storage is a boxed
        // __value__* slot; loadValue then yields the %__value__ struct and the
        // scalar writes below hand it to i64/double/i8 parameters (module
        // verify failure / #16565-class StructGEP crash). Copy the box instead.
        if (null === $arg->valueBoxAliasPtr
            && \in_array($arg->type, [
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::TYPE_NATIVE_DOUBLE,
                JITVariable::TYPE_NATIVE_BOOL,
                JITVariable::TYPE_STRING,
            ], true)
            && \in_array(
                $context->getStringFromType($arg->value->typeOf()),
                ['__value__*', '__value__value*'],
                true
            )) {
            JitValueBox::copyFromPointer(
                $context,
                $slot,
                JitValueBox::normalizeValuePtr($context, $arg->value)
            );

            return;
        }
        switch ($arg->type) {
            case JITVariable::TYPE_NULL:
                $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
                return;
            case JITVariable::TYPE_NATIVE_LONG:
                JitValueBox::writeLong($context, $slot, $context->helper->loadValue($arg));
                return;
            case JITVariable::TYPE_NATIVE_DOUBLE:
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    $ptr,
                    $context->helper->loadValue($arg)
                );
                return;
            case JITVariable::TYPE_NATIVE_BOOL:
                JitValueBox::writeBool($context, $slot, $context->helper->loadValue($arg));
                return;
            case JITVariable::TYPE_STRING:
                $str = $context->helper->loadValue($arg);
                $owned = $context->builder->call(
                    $context->lookupFunction('__string__separate'),
                    $str
                );
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $ptr,
                    $owned
                );
                return;
            case JITVariable::TYPE_VALUE:
                // valuePtrFromVariable, not loadValue: loadValue can yield the
                // __value__ struct BY VALUE (kind-dependent) and StructGEP on a
                // non-pointer receiver segfaults LLVM 9 (#16565 class).
                JitValueBox::copyFromPointer(
                    $context,
                    $slot,
                    JitValueBox::valuePtrFromVariable($context, $arg)
                );
                return;
            case JITVariable::TYPE_OBJECT:
                $context->builder->call(
                    $context->lookupFunction('__value__writeObject'),
                    $ptr,
                    $context->helper->loadValue($arg)
                );
                return;
            case JITVariable::TYPE_HASHTABLE:
                $context->builder->call(
                    $context->lookupFunction('__value__writeHashtable'),
                    $ptr,
                    $context->helper->loadValue($arg)
                );
                return;
            default:
                if ($arg->type & JITVariable::IS_NATIVE_ARRAY) {
                    $htVar = \PHPCompiler\JIT\HashTableHelper::coerceToPackedHashtable($context, $arg);
                    $context->builder->call(
                        $context->lookupFunction('__value__writeHashtable'),
                        $ptr,
                        $context->helper->loadValue($htVar)
                    );

                    return;
                }
                throw new \LogicException(
                    'sprintf() argument must be a scalar value in this compiler build'
                );
        }
    }
}
