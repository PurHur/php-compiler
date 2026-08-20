<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ZendDoubleStringRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\VmResourceIdString;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for sprintf() (%s, %d, %f, %%).
 *
 * php-src ext/standard/formatted_print.c — conversion specifier drives coercion (#33010).
 */
final class JitSprintf
{
    public static function formatWithFmt(Context $context, Value $fmt, JITVariable ...$args): Value
    {
        return self::formatWithFmtLiteral($context, $fmt, null, ...$args);
    }

    /** Prefer when the format literal is known so %s can stringify float/int (#33010). */
    public static function formatWithFmtLiteral(
        Context $context,
        Value $fmt,
        ?string $fmtLiteral,
        JITVariable ...$args
    ): Value {
        $wrapped = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $fmt);
        if (null !== $fmtLiteral) {
            $wrapped->compileTimeString = $fmtLiteral;
        }

        return self::format($context, $wrapped, ...$args);
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
        // %s/%S must stringify float/int first (php-src formatted_print.c) (#33010).
        \PHPCompiler\JIT\LibcExtern::ensureSnprintf($context);
        ZendDoubleStringRuntime::ensureLinked($context);

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
        $specs = self::parseConversionSpecs($args[0]->compileTimeString);
        for ($i = 0; $i < $numArgs; ++$i) {
            $spec = $specs[$i] ?? null;
            $extracted = self::extractSnprintfArg($context, $args[$i + 1], $toFree, $spec);
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
     * Conversion specifiers for value args (excludes %% and width/precision * args).
     *
     * @return list<string>
     */
    public static function parseConversionSpecs(?string $fmt): array
    {
        if (null === $fmt || '' === $fmt) {
            return [];
        }
        $specs = [];
        $len = \strlen($fmt);
        for ($i = 0; $i < $len; ++$i) {
            if ('%' !== $fmt[$i]) {
                continue;
            }
            ++$i;
            if ($i >= $len) {
                break;
            }
            if ('%' === $fmt[$i]) {
                continue;
            }
            while ($i < $len && \str_contains("#0-+ '", $fmt[$i])) {
                ++$i;
            }
            if ($i < $len && '*' === $fmt[$i]) {
                // Width from next arg — not a value conversion; skip consuming a value spec slot
                // by recording a star marker so arg indexing stays aligned with snprintf.
                $specs[] = '*';
                ++$i;
            } else {
                while ($i < $len && \ctype_digit($fmt[$i])) {
                    ++$i;
                }
            }
            if ($i < $len && '.' === $fmt[$i]) {
                ++$i;
                if ($i < $len && '*' === $fmt[$i]) {
                    $specs[] = '*';
                    ++$i;
                } else {
                    while ($i < $len && \ctype_digit($fmt[$i])) {
                        ++$i;
                    }
                }
            }
            if ($i < $len) {
                $specs[] = $fmt[$i];
            }
        }

        return $specs;
    }

    private static function isStringConversionSpec(?string $spec): bool
    {
        return 's' === $spec || 'S' === $spec;
    }

    /**
     * Extract a typed value from a JIT variable for use as a snprintf vararg.
     *
     * Returns i64 for longs/bools, double for floats, char* for strings.
     * For %s/%S, float/int are stringified first (#33010).
     * String args are NUL-terminated into a malloc'd buffer tracked in $toFree.
     *
     * @param Value[] $toFree collects malloc'd buffers to free after snprintf
     */
    private static function extractSnprintfArg(
        Context $context,
        JITVariable $arg,
        array &$toFree,
        ?string $spec = null
    ): Value {
        $asString = self::isStringConversionSpec($spec);

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
                    $dbl = $context->builder->call(
                        $context->lookupFunction('__value__readDouble'),
                        $valuePtr
                    );
                    if ($asString) {
                        return self::doubleAsSnprintfString($context, $dbl, $toFree);
                    }

                    return $dbl;
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
                    $lng = $context->builder->call(
                        $context->lookupFunction('__value__readLong'),
                        $valuePtr
                    );
                    if ($asString) {
                        return self::longAsSnprintfString($context, $lng, $toFree);
                    }

                    return $lng;
            }
        }
        switch ($arg->type) {
            case JITVariable::TYPE_NATIVE_LONG:
            case JITVariable::TYPE_NATIVE_BOOL:
                $lng = $context->helper->loadValue($arg);
                if ($asString) {
                    return self::longAsSnprintfString($context, $lng, $toFree);
                }

                return $lng;
            case JITVariable::TYPE_NATIVE_DOUBLE:
                $dbl = $context->helper->loadValue($arg);
                if ($asString) {
                    return self::doubleAsSnprintfString($context, $dbl, $toFree);
                }

                return $dbl;
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
                $lng = $context->builder->call(
                    $context->lookupFunction('__value__readLong'),
                    $valuePtr
                );
                if ($asString) {
                    return self::longAsSnprintfString($context, $lng, $toFree);
                }

                return $lng;
            default:
                return $context->helper->loadValue($arg);
        }
    }

    /** @param Value[] $toFree */
    private static function doubleAsSnprintfString(Context $context, Value $dbl, array &$toFree): Value
    {
        $str = ZendDoubleStringRuntime::formatGcvt($context, $dbl);
        $sep = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $nul = \PHPCompiler\JIT\Builtin\SprintfSnprintfRuntime::nullTerminatedCopyPublic($context, $sep);
        $toFree[] = $nul;

        return $nul;
    }

    /** @param Value[] $toFree */
    private static function longAsSnprintfString(Context $context, Value $lng, array &$toFree): Value
    {
        $str = VmResourceIdString::formatBoxedNativeLong($context, $lng);
        $sep = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $nul = \PHPCompiler\JIT\Builtin\SprintfSnprintfRuntime::nullTerminatedCopyPublic($context, $sep);
        $toFree[] = $nul;

        return $nul;
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
