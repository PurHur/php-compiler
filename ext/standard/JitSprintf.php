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

        // Multi-arg: direct libc snprintf — bypasses broken NestedJIT pack path.
        // At compile time we know arg count and types, so we emit a single
        // snprintf(buf, size, fmt_nul, typed1, typed2, ...) call.
        \PHPCompiler\JIT\LibcExtern::ensureSnprintf($context);

        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $strPtr = $context->getTypeFromString('__string__*');

        $fmtNul = \PHPCompiler\JIT\Builtin\SprintfSnprintfRuntime::nullTerminatedCopyPublic($context, $fmt);

        $bufSize = 1024;
        $outBuf = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $sizeT->constInt($bufSize, false)
        );
        $outChar = $context->builder->pointerCast($outBuf, $charPtr);
        $snprintfArgs = [
            $outChar,
            $sizeT->constInt($bufSize, false),
            $fmtNul,
        ];
        $toFree = [];
        for ($i = 0; $i < $numArgs; ++$i) {
            $extracted = self::extractSnprintfArg($context, $args[$i + 1], $toFree);
            $snprintfArgs[] = $extracted;
        }

        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            ...$snprintfArgs
        );

        $len = $context->builder->zExt($written, $i64);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $context->builder->pointerCast($outBuf, $i8p)
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $outBuf);
        $context->builder->call($context->lookupFunction('__mm__free'), $fmtNul);
        foreach ($toFree as $ptr) {
            $context->builder->call($context->lookupFunction('__mm__free'), $ptr);
        }

        return $result;
    }

    /**
     * Extract a typed value from a JIT variable for use as a snprintf vararg.
     *
     * Returns i64 for longs/bools, double for floats, char* for strings.
     * String args are NUL-terminated into a malloc'd buffer tracked in $toFree.
     *
     * @param Value[] $toFree collects malloc'd buffers to free after snprintf
     */
    private static function extractSnprintfArg(Context $context, JITVariable $arg, array &$toFree): Value
    {
        // When inference says native type but storage is __value__*, read from the box.
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
            $valuePtr = JitValueBox::normalizeValuePtr($context, $arg->value);
            switch ($arg->type) {
                case JITVariable::TYPE_NATIVE_DOUBLE:
                    return $context->builder->call(
                        $context->lookupFunction('__value__readDouble'),
                        $valuePtr
                    );
                case JITVariable::TYPE_STRING:
                    $strVal = $context->builder->call(
                        $context->lookupFunction('__value__readString'),
                        $valuePtr
                    );
                    $strSep = $context->builder->call(
                        $context->lookupFunction('__string__separate'),
                        $strVal
                    );
                    $nul = \PHPCompiler\JIT\Builtin\SprintfSnprintfRuntime::nullTerminatedCopyPublic($context, $strSep);
                    $toFree[] = $nul;

                    return $nul;
                default:
                    return $context->builder->call(
                        $context->lookupFunction('__value__readLong'),
                        $valuePtr
                    );
            }
        }
        switch ($arg->type) {
            case JITVariable::TYPE_NATIVE_LONG:
            case JITVariable::TYPE_NATIVE_BOOL:
                return $context->helper->loadValue($arg);
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $context->helper->loadValue($arg);
            case JITVariable::TYPE_STRING:
                $str = $context->helper->loadValue($arg);
                $owned = $context->builder->call(
                    $context->lookupFunction('__string__separate'),
                    $str
                );
                $nul = \PHPCompiler\JIT\Builtin\SprintfSnprintfRuntime::nullTerminatedCopyPublic($context, $owned);
                $toFree[] = $nul;

                return $nul;
            case JITVariable::TYPE_VALUE:
                $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);

                return $context->builder->call(
                    $context->lookupFunction('__value__readLong'),
                    $valuePtr
                );
            default:
                return $context->helper->loadValue($arg);
        }
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
