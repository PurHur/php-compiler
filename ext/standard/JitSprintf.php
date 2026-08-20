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

        $constFmt = self::constantFormatString($args[0]);
        $conversions = null !== $constFmt ? self::conversionSpecifiers($constFmt) : null;
        // Non-constant format: NestedJIT argv path (safe for %s + float) (#33010).
        if (null === $conversions) {
            return self::formatViaCompilerSprintf($context, $fmt, ...array_slice($args, 1));
        }

        // Multi-arg with compile-time format: libc snprintf with specifier-driven coercion.
        \PHPCompiler\JIT\LibcExtern::ensureSnprintf($context);

        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');

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
            $conv = $conversions[$i] ?? '';
            if ('s' === $conv || 'S' === $conv) {
                $snprintfArgs[] = self::extractAsCString($context, $args[$i + 1], $toFree);
            } else {
                $snprintfArgs[] = self::extractSnprintfArg($context, $args[$i + 1], $toFree);
            }
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

    /** @param JITVariable ...$valueArgs value args only (no format) */
    private static function formatViaCompilerSprintf(Context $context, Value $fmt, JITVariable ...$valueArgs): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $valueType = $context->getTypeFromString('__value__');
        $argc = \count($valueArgs);
        $argv = $context->builder->arrayMalloc($valueType, $i64->constInt($argc, false));
        for ($i = 0; $i < $argc; ++$i) {
            $slot = $context->builder->gep($argv, $i64->constInt($i, false));
            self::writeArg($context, $slot, $valueArgs[$i]);
        }

        return $context->builder->call(
            $context->lookupFunction('__compiler_sprintf'),
            $fmt,
            $i64->constInt($argc, false),
            $argv
        );
    }

    private static function constantFormatString(JITVariable $fmtArg): ?string
    {
        if (null !== $fmtArg->compileTimeString && '' !== $fmtArg->compileTimeString) {
            return $fmtArg->compileTimeString;
        }

        return null;
    }

    /**
     * First N conversion specifiers (skipping %%); lower-case letter only.
     *
     * @return list<string>
     */
    private static function conversionSpecifiers(string $fmt): array
    {
        $out = [];
        $len = \strlen($fmt);
        for ($i = 0; $i < $len; ++$i) {
            if ('%' !== $fmt[$i]) {
                continue;
            }
            if ($i + 1 < $len && '%' === $fmt[$i + 1]) {
                ++$i;
                continue;
            }
            ++$i;
            while ($i < $len && str_contains("#0- +'", $fmt[$i])) {
                ++$i;
            }
            if ($i < $len && '*' === $fmt[$i]) {
                ++$i;
            } else {
                while ($i < $len && $fmt[$i] >= '0' && $fmt[$i] <= '9') {
                    ++$i;
                }
            }
            if ($i < $len && '.' === $fmt[$i]) {
                ++$i;
                if ($i < $len && '*' === $fmt[$i]) {
                    ++$i;
                } else {
                    while ($i < $len && $fmt[$i] >= '0' && $fmt[$i] <= '9') {
                        ++$i;
                    }
                }
            }
            while ($i < $len && str_contains('hlLzjt', $fmt[$i])) {
                ++$i;
            }
            if ($i < $len) {
                $out[] = $fmt[$i];
            }
        }

        return $out;
    }

    /**
     * Coerce any scalar arg to a NUL-terminated C string for `%s` (#33010).
     *
     * @param Value[] $toFree
     */
    private static function extractAsCString(Context $context, JITVariable $arg, array &$toFree): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return self::extractSnprintfArg($context, $arg, $toFree);
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
            $saved = null;
            try {
                $saved = $context->builder->getInsertBlock();
            } catch (\Throwable) {
            }
            \PHPCompiler\JIT\Builtin\ZendDoubleStringRuntime::ensureStandaloneBodies($context);
            if (null !== $saved) {
                $context->builder->positionAtEnd($saved);
            }
            $dbl = self::loadDouble($context, $arg);
            $str = \PHPCompiler\JIT\Builtin\ZendDoubleStringRuntime::formatGcvt($context, $dbl);
            $sep = $context->builder->call($context->lookupFunction('__string__separate'), $str);
            $nul = \PHPCompiler\JIT\Builtin\SprintfSnprintfRuntime::nullTerminatedCopyPublic($context, $sep);
            $toFree[] = $nul;

            return $nul;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type || JITVariable::TYPE_NATIVE_BOOL === $arg->type) {
            \PHPCompiler\JIT\LibcExtern::ensureSnprintf($context);
            $lng = self::extractSnprintfArg($context, $arg, $toFree);
            $charPtr = $context->getTypeFromString('char*');
            $sizeT = $context->getTypeFromString('size_t');
            $numBuf = $context->builder->call(
                $context->lookupFunction('__mm__malloc'),
                $sizeT->constInt(64, false)
            );
            $numChar = $context->builder->pointerCast($numBuf, $charPtr);
            $lldFmt = $context->builder->pointerCast($context->constantFromString('%lld'), $charPtr);
            $context->builder->call(
                $context->lookupFunction('snprintf'),
                $numChar,
                $sizeT->constInt(64, false),
                $lldFmt,
                $lng
            );
            $toFree[] = $numBuf;

            return $numChar;
        }

        return $context->builder->pointerCast(
            $context->constantFromString(''),
            $context->getTypeFromString('char*')
        );
    }

    private static function loadDouble(Context $context, JITVariable $arg): Value
    {
        if (null === $arg->valueBoxAliasPtr
            && \in_array(
                $context->getStringFromType($arg->value->typeOf()),
                ['__value__*', '__value__value*'],
                true
            )) {
            $valuePtr = JitValueBox::normalizeValuePtr($context, $arg->value);

            return $context->builder->call(
                $context->lookupFunction('__value__readDouble'),
                $valuePtr
            );
        }

        return $context->helper->loadValue($arg);
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
