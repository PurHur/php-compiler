<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArithOverflow;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
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
        $i32 = $context->getTypeFromString('int32');

        // libc %d/%u/%x expect 32-bit int on LP64; we pass i64 (zend_long). Promote
        // conversions to %lld/%llu/%llx so PHP_INT_MIN does not print as 0 (#36386).
        $fmtForSnprintf = self::promoteIntegerConversionsForInt64($constFmt);
        $fmtStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($fmtForSnprintf), false),
            $context->builder->pointerCast(
                $context->constantFromString($fmtForSnprintf),
                $i8p
            )
        );
        $fmtNul = \PHPCompiler\JIT\Builtin\SprintfSnprintfRuntime::nullTerminatedCopyPublic(
            $context,
            $fmtStr
        );

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
            // '*' = sequential star width/precision — always i64 for libc snprintf (#34969).
            // Without this slot, %*s maps the width int onto %s → SIGSEGV (#33010 class).
            if ('*' === $conv) {
                // libc `*` width expects int; trunc i64 (widths are small).
                $snprintfArgs[] = $context->builder->trunc(
                    self::extractStarLongArg($context, $args[$i + 1]),
                    $i32
                );
            } elseif ('s' === $conv || 'S' === $conv) {
                $snprintfArgs[] = self::extractAsCString($context, $args[$i + 1], $toFree);
            } elseif (\in_array($conv, ['f', 'e', 'g', 'a'], true)) {
                $snprintfArgs[] = self::extractSnprintfFloatArg($context, $args[$i + 1]);
            } elseif ('c' === $conv) {
                // %c stays 32-bit; trunc zend_long.
                $snprintfArgs[] = $context->builder->trunc(
                    self::extractSnprintfLongArg($context, $args[$i + 1]),
                    $i32
                );
            } elseif (\in_array($conv, ['d', 'i', 'u', 'o', 'x', 'b'], true)) {
                // Integer conversions (incl. PHP %b): zval_get_long / zend_dval_to_lval —
                // never pass a double (or overflow-arm 0 i64) to libc (#36386 leftover of
                // #37051 / #37075). php-src formatted_print.c — %b is unsigned bit pattern.
                $snprintfArgs[] = self::extractSnprintfLongArg($context, $args[$i + 1]);
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
     * Per-arg snprintf roles for a compile-time format (skipping %%).
     *
     * Each sequential `*` width/precision pushes `'*'` so argv stays aligned with
     * libc snprintf (php-src formatted_print.c). Conversion letter is lower-cased.
     *
     * Example: `%*s` → `['*', 's']`; `%.*s` → `['*', 's']`; `%*.*s` → `['*', '*', 's']`.
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
                $out[] = '*';
                ++$i;
            } else {
                while ($i < $len && $fmt[$i] >= '0' && $fmt[$i] <= '9') {
                    ++$i;
                }
            }
            if ($i < $len && '.' === $fmt[$i]) {
                ++$i;
                if ($i < $len && '*' === $fmt[$i]) {
                    $out[] = '*';
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
                $out[] = \strtolower($fmt[$i]);
            }
        }

        return $out;
    }

    /**
     * Promote %d/%i/%u/%o/%x/%X/%b to %lld/… so libc receives zend_long-width args (#36386).
     *
     * On LP64, bare `%d` reads a 32-bit int from the vararg; PHP_INT_MIN's low
     * half is 0 so overflow floats printed as {@code 0}. Same for PHP `%b` (C23
     * `%b` / `%llb` on glibc). Length modifiers already present (`l`/`ll`/`z`/…)
     * are left unchanged. `%c` is not promoted (caller truncates to i32).
     */
    private static function promoteIntegerConversionsForInt64(string $fmt): string
    {
        $out = '';
        $len = \strlen($fmt);
        for ($i = 0; $i < $len; ++$i) {
            $out .= $fmt[$i];
            if ('%' !== $fmt[$i]) {
                continue;
            }
            if ($i + 1 < $len && '%' === $fmt[$i + 1]) {
                $out .= '%';
                ++$i;
                continue;
            }
            ++$i;
            while ($i < $len && \str_contains("#0- +'", $fmt[$i])) {
                $out .= $fmt[$i];
                ++$i;
            }
            if ($i < $len && '*' === $fmt[$i]) {
                $out .= '*';
                ++$i;
            } else {
                while ($i < $len && $fmt[$i] >= '0' && $fmt[$i] <= '9') {
                    $out .= $fmt[$i];
                    ++$i;
                }
            }
            if ($i < $len && '.' === $fmt[$i]) {
                $out .= '.';
                ++$i;
                if ($i < $len && '*' === $fmt[$i]) {
                    $out .= '*';
                    ++$i;
                } else {
                    while ($i < $len && $fmt[$i] >= '0' && $fmt[$i] <= '9') {
                        $out .= $fmt[$i];
                        ++$i;
                    }
                }
            }
            $hadLength = false;
            while ($i < $len && \str_contains('hlLzjt', $fmt[$i])) {
                $out .= $fmt[$i];
                $hadLength = true;
                ++$i;
            }
            if ($i < $len) {
                $conv = $fmt[$i];
                if (!$hadLength && \str_contains('diuoxXb', $conv)) {
                    $out .= 'll';
                }
                $out .= $conv;
            }
        }

        return $out;
    }

    /**
     * Star width/precision arg as int64 for libc snprintf (#34969).
     *
     * php-src: formatted_print.c — `*` consumes next arg via zval_get_long.
     */
    private static function extractStarLongArg(Context $context, JITVariable $arg): Value
    {
        return self::extractSnprintfLongArg($context, $arg);
    }

    /**
     * Integer snprintf args (%d/%i/%u/%o/%x/%b/%c and `*` width): zval_get_long shape.
     *
     * Overflowable native-long (lazy ±/×/`/` #37051) must not use the overflow-arm
     * dummy i64 0 — coerce the cold f64 via zend_dval_to_lval. Native doubles must
     * not be passed as IEEE bits to libc `%d` (ABI mismatch → prints 0).
     *
     * Uses plain fptosi (no E_DEPRECATED bridge) — php-src printf does not warn on
     * float→long for %d; the precision-warning helper clears the insert block mid
     * snprintf arg setup and segfaults the compiler (#36386).
     *
     * php-src: ext/standard/formatted_print.c php_formatted_print / zval_get_long;
     * Zend/zend_operators.h zend_dval_to_lval.
     */
    private static function extractSnprintfLongArg(Context $context, JITVariable $arg): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if (JITVariable::TYPE_NULL === $arg->type) {
            return $i64->constInt(0, false);
        }
        // Lazy overflow flag + f64 slot: pick long or zend_dval_to_lval(double).
        if (
            null !== $arg->longArithOverflowFlag
            && null !== $arg->longArithOverflowDoubleSlot
            && JITVariable::TYPE_NATIVE_LONG === $arg->type
        ) {
            return self::extractOverflowableNativeLongAsLong($context, $arg);
        }
        if (
            null !== $arg->longArithOverflowFlag
            && null !== $arg->longArithOverflowPromoted
            && JITVariable::TYPE_NATIVE_LONG === $arg->type
        ) {
            $arg = JitLongArithOverflow::materializeOverflowableNativeLong($context, $arg);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type
            || JITVariable::TYPE_NATIVE_BOOL === $arg->type
        ) {
            if (null === $arg->valueBoxAliasPtr
                && \in_array(
                    $context->getStringFromType($arg->value->typeOf()),
                    ['__value__*', '__value__value*'],
                    true
                )
            ) {
                $valuePtr = JitValueBox::normalizeValuePtr($context, $arg->value);

                return $context->builder->call(
                    $context->lookupFunction('__value__readLong'),
                    $valuePtr
                );
            }

            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return \PHPCompiler\JIT\JitLongArg::doubleToZendLong(
                $context,
                $context->helper->loadValue($arg)
            );
        }
        if (JITVariable::TYPE_VALUE === $arg->type
            || null !== $arg->valueBoxAliasPtr
            || \in_array(
                $context->getStringFromType($arg->value->typeOf()),
                ['__value__*', '__value__value*'],
                true
            )
        ) {
            $valuePtr = null !== $arg->valueBoxAliasPtr
                ? JitValueBox::normalizeValuePtr($context, $arg->valueBoxAliasPtr)
                : JitValueBox::valuePtrFromVariable($context, $arg);

            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $valuePtr
            );
        }
        if (JITVariable::TYPE_STRING === $arg->type) {
            return \PHPCompiler\JIT\JitLongArg::lower($context, $arg, 'sprintf');
        }

        return $i64->constInt(0, false);
    }

    /**
     * Overflowable native-long → i64 for %d without heap-boxing mid-snprintf.
     */
    private static function extractOverflowableNativeLongAsLong(
        Context $context,
        JITVariable $arg
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'sprintf_as_d_ov_cont');
        $i64 = $context->getTypeFromString('int64');
        $flag = $arg->longArithOverflowFlag;
        $isOv = \PHPLLVM\Type::KIND_POINTER === $flag->typeOf()->getKind()
            ? $context->builder->load($flag)
            : $flag;

        $ovBb = BasicBlockHelper::append($context, 'sprintf_as_d_ov_dbl');
        $okBb = BasicBlockHelper::append($context, 'sprintf_as_d_ok');
        $outBb = BasicBlockHelper::append($context, 'sprintf_as_d_out');
        $context->builder->branchIf($isOv, $ovBb, $okBb);

        $context->builder->positionAtEnd($okBb);
        $longBits = JITVariable::KIND_VARIABLE === $arg->kind
            ? $context->builder->load($arg->value)
            : $arg->value;
        $longTy = $context->getStringFromType($longBits->typeOf());
        if ('int64*' === $longTy || 'long long*' === $longTy) {
            $longBits = $context->builder->load($longBits);
        }
        $okEnd = $context->builder->getInsertBlock();
        $context->builder->branch($outBb);

        $context->builder->positionAtEnd($ovBb);
        $fd = $context->builder->load($arg->longArithOverflowDoubleSlot);
        $ovLong = \PHPCompiler\JIT\JitLongArg::doubleToZendLong($context, $fd);
        $ovEnd = $context->builder->getInsertBlock();
        $context->builder->branch($outBb);

        $context->builder->positionAtEnd($outBb);
        $phi = $context->builder->phi($i64, 'sprintf_as_d_phi');
        $phi->addIncoming($longBits, $okEnd);
        $phi->addIncoming($ovLong, $ovEnd);

        return $phi;
    }

    /**
     * Coerce any scalar arg to a NUL-terminated C string for `%s` (#33010).
     *
     * Boxed {@see JITVariable::TYPE_VALUE} args (lazy ±/×/`/` overflow materialize,
     * #36386 leftover of #37051) must type-switch — the previous fall-through returned
     * a constant empty C string so {@code printf("%s", $x)} printed nothing while
     * {@code %g}/{@code print_r}/{@code echo} matched Zend.
     *
     * php-src: ext/standard/formatted_print.c php_formatted_print / convert_to_string.
     *
     * @param Value[] $toFree
     */
    private static function extractAsCString(Context $context, JITVariable $arg, array &$toFree): Value
    {
        // Overflowable native-long SSA (cold f64 slot): stringify from the flag +
        // double slot without materializing a heap box mid-snprintf (#36386).
        // Full materializeOverflowableNativeLong here segfaulted on inline
        // `printf("%s", PHP_INT_MAX + 1)` while assigned locals (already boxed) were fine.
        if (
            null !== $arg->longArithOverflowFlag
            && null !== $arg->longArithOverflowDoubleSlot
            && JITVariable::TYPE_NATIVE_LONG === $arg->type
        ) {
            return self::extractOverflowableNativeLongAsCString($context, $arg, $toFree);
        }
        if (
            null !== $arg->longArithOverflowFlag
            && null !== $arg->longArithOverflowPromoted
            && JITVariable::TYPE_NATIVE_LONG === $arg->type
        ) {
            // Legacy promoted box — stringify as TYPE_VALUE.
            $arg = JitLongArithOverflow::materializeOverflowableNativeLong($context, $arg);
        }
        if (JITVariable::TYPE_NULL === $arg->type) {
            return $context->builder->pointerCast(
                $context->constantFromString(''),
                $context->getTypeFromString('char*')
            );
        }
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
        if (
            JITVariable::TYPE_VALUE === $arg->type
            || null !== $arg->valueBoxAliasPtr
            || \in_array(
                $context->getStringFromType($arg->value->typeOf()),
                ['__value__*', '__value__value*'],
                true
            )
        ) {
            return self::extractBoxedValueAsCString($context, $arg, $toFree);
        }

        return $context->builder->pointerCast(
            $context->constantFromString(''),
            $context->getTypeFromString('char*')
        );
    }

    /**
     * {@code %s} of a lazy-overflow native-long (flag + f64 slot) without heap boxing.
     *
     * @param Value[] $toFree
     */
    private static function extractOverflowableNativeLongAsCString(
        Context $context,
        JITVariable $arg,
        array &$toFree
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'sprintf_as_s_ov_cont');
        \PHPCompiler\JIT\LibcExtern::ensureSnprintf($context);
        $saved = null;
        try {
            $saved = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }
        \PHPCompiler\JIT\Builtin\ZendDoubleStringRuntime::ensureStandaloneBodies($context);
        if (null !== $saved) {
            $context->builder->positionAtEnd($saved);
        }

        $flag = $arg->longArithOverflowFlag;
        $isOv = \PHPLLVM\Type::KIND_POINTER === $flag->typeOf()->getKind()
            ? $context->builder->load($flag)
            : $flag;
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');

        $ovBb = BasicBlockHelper::append($context, 'sprintf_as_s_ov_dbl');
        $okBb = BasicBlockHelper::append($context, 'sprintf_as_s_ov_lng');
        $outBb = BasicBlockHelper::append($context, 'sprintf_as_s_ov_out');
        $context->builder->branchIf($isOv, $ovBb, $okBb);

        $context->builder->positionAtEnd($ovBb);
        $fd = $context->builder->load($arg->longArithOverflowDoubleSlot);
        $dblStr = \PHPCompiler\JIT\Builtin\ZendDoubleStringRuntime::formatGcvt($context, $fd);
        $dblSep = $context->builder->call($context->lookupFunction('__string__separate'), $dblStr);
        $dblNul = \PHPCompiler\JIT\Builtin\SprintfSnprintfRuntime::nullTerminatedCopyPublic($context, $dblSep);
        // Do NOT push per-arm allocas into $toFree during IR gen — both arms would be
        // freed at runtime even when only one executed (UAF/segfault, #36386).
        $endOv = $context->builder->getInsertBlock();
        $context->builder->branch($outBb);

        $context->builder->positionAtEnd($okBb);
        $longBits = JITVariable::KIND_VARIABLE === $arg->kind
            ? $context->builder->load($arg->value)
            : $arg->value;
        $longTy = $context->getStringFromType($longBits->typeOf());
        if ('int64*' === $longTy || 'long long*' === $longTy) {
            $longBits = $context->builder->load($longBits);
        }
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
            $longBits
        );
        $endOk = $context->builder->getInsertBlock();
        $context->builder->branch($outBb);

        $context->builder->positionAtEnd($outBb);
        $phi = $context->builder->phi($charPtr, 'sprintf_as_s_ov_phi');
        $phi->addIncoming($dblNul, $endOv);
        $phi->addIncoming($numChar, $endOk);
        $toFree[] = $phi;

        return $phi;
    }

    /**
     * {@code %s} of a boxed {@see __value__*} — type-switch like
     * {@see \PHPCompiler\JIT\Builtin\SprintfSnprintfRuntime} as_s_* (#36386).
     *
     * @param Value[] $toFree
     */
    private static function extractBoxedValueAsCString(Context $context, JITVariable $arg, array &$toFree): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'sprintf_as_s_vbox_cont');
        \PHPCompiler\JIT\LibcExtern::ensureSnprintf($context);
        $saved = null;
        try {
            $saved = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }
        \PHPCompiler\JIT\Builtin\ZendDoubleStringRuntime::ensureStandaloneBodies($context);
        if (null !== $saved) {
            $context->builder->positionAtEnd($saved);
        }

        $valuePtr = null !== $arg->valueBoxAliasPtr
            ? JitValueBox::normalizeValuePtr($context, $arg->valueBoxAliasPtr)
            : JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');

        $dblBb = BasicBlockHelper::append($context, 'sprintf_as_s_vbox_dbl');
        $lngBb = BasicBlockHelper::append($context, 'sprintf_as_s_vbox_lng');
        $boolBb = BasicBlockHelper::append($context, 'sprintf_as_s_vbox_bool');
        $strBb = BasicBlockHelper::append($context, 'sprintf_as_s_vbox_str');
        $fbBb = BasicBlockHelper::append($context, 'sprintf_as_s_vbox_fb');
        $outBb = BasicBlockHelper::append($context, 'sprintf_as_s_vbox_out');

        $isDbl = $context->builder->or(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(VmVariable::TYPE_FLOAT, false)
            ),
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false)
            )
        );
        $afterDbl = BasicBlockHelper::append($context, 'sprintf_as_s_vbox_after_dbl');
        $context->builder->branchIf($isDbl, $dblBb, $afterDbl);

        $context->builder->positionAtEnd($afterDbl);
        $isLng = $context->builder->or(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(VmVariable::TYPE_INTEGER, false)
            ),
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)
            )
        );
        $afterLng = BasicBlockHelper::append($context, 'sprintf_as_s_vbox_after_lng');
        $context->builder->branchIf($isLng, $lngBb, $afterLng);

        $context->builder->positionAtEnd($afterLng);
        $isBool = $context->builder->or(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(VmVariable::TYPE_BOOLEAN, false)
            ),
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false)
            )
        );
        $afterBool = BasicBlockHelper::append($context, 'sprintf_as_s_vbox_after_bool');
        $context->builder->branchIf($isBool, $boolBb, $afterBool);

        $context->builder->positionAtEnd($afterBool);
        $isStr = $context->builder->or(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(VmVariable::TYPE_STRING, false)
            ),
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_STRING, false)
            )
        );
        $context->builder->branchIf($isStr, $strBb, $fbBb);

        $context->builder->positionAtEnd($dblBb);
        $dbl = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $valuePtr
        );
        $dblStr = \PHPCompiler\JIT\Builtin\ZendDoubleStringRuntime::formatGcvt($context, $dbl);
        $dblSep = $context->builder->call($context->lookupFunction('__string__separate'), $dblStr);
        $dblNul = \PHPCompiler\JIT\Builtin\SprintfSnprintfRuntime::nullTerminatedCopyPublic($context, $dblSep);
        $endDbl = $context->builder->getInsertBlock();
        $context->builder->branch($outBb);

        $context->builder->positionAtEnd($lngBb);
        $lng = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
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
        $endLng = $context->builder->getInsertBlock();
        $context->builder->branch($outBb);

        $context->builder->positionAtEnd($boolBb);
        // No __value__readBool ABI — writeBool stores int8 (#29109 / #21892).
        $boolByte = JitValueBox::readBoolByte($context, $valuePtr);
        $isTrue = $context->builder->icmp(
            Builder::INT_NE,
            $boolByte,
            $i8->constInt(0, false)
        );
        // Zend convert_to_string: true → "1", false → "" (zend_operators.c).
        // Heap-copy so the selected phi is always __mm__free-safe (#36386).
        $trueLit = $context->constantFromString('1');
        $falseLit = $context->constantFromString('');
        $boolLit = $context->builder->select($isTrue, $trueLit, $falseLit);
        $boolNul = \PHPCompiler\JIT\Builtin\SprintfSnprintfRuntime::nullTerminatedCopyPublic(
            $context,
            $context->builder->call(
                $context->lookupFunction('__string__init'),
                $context->builder->select(
                    $isTrue,
                    $context->getTypeFromString('int64')->constInt(1, false),
                    $context->getTypeFromString('int64')->constInt(0, false)
                ),
                $context->builder->pointerCast($boolLit, $context->getTypeFromString('int8*'))
            )
        );
        $endBool = $context->builder->getInsertBlock();
        $context->builder->branch($outBb);

        $context->builder->positionAtEnd($strBb);
        $strVal = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $strSep = $context->builder->call($context->lookupFunction('__string__separate'), $strVal);
        $strNul = \PHPCompiler\JIT\Builtin\SprintfSnprintfRuntime::nullTerminatedCopyPublic($context, $strSep);
        $endStr = $context->builder->getInsertBlock();
        $context->builder->branch($outBb);

        $context->builder->positionAtEnd($fbBb);
        $emptyNul = \PHPCompiler\JIT\Builtin\SprintfSnprintfRuntime::nullTerminatedCopyPublic(
            $context,
            $context->builder->call(
                $context->lookupFunction('__string__init'),
                $context->getTypeFromString('int64')->constInt(0, false),
                $context->builder->pointerCast(
                    $context->constantFromString(''),
                    $context->getTypeFromString('int8*')
                )
            )
        );
        $endFb = $context->builder->getInsertBlock();
        $context->builder->branch($outBb);

        $context->builder->positionAtEnd($outBb);
        $phi = $context->builder->phi($charPtr, 'sprintf_as_s_vbox_phi');
        $phi->addIncoming($dblNul, $endDbl);
        $phi->addIncoming($numChar, $endLng);
        $phi->addIncoming($boolNul, $endBool);
        $phi->addIncoming($strNul, $endStr);
        $phi->addIncoming($emptyNul, $endFb);
        // Single free of the selected arm — never push per-arm buffers into $toFree
        // during IR gen (would free unallocated sibling arms → segfault, #36386).
        $toFree[] = $phi;

        return $phi;
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

    /** `%f` / `%e` / `%g` / `%a` — boxed floats must use readDouble, not readLong (#36353). */
    private static function extractSnprintfFloatArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NULL === $arg->type) {
            return $context->getTypeFromString('double')->constReal(0.0);
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type
            || null !== $arg->valueBoxAliasPtr
            || \in_array(
                $context->getStringFromType($arg->value->typeOf()),
                ['__value__*', '__value__value*'],
                true
            )) {
            $valuePtr = null !== $arg->valueBoxAliasPtr
                ? JitValueBox::normalizeValuePtr($context, $arg->valueBoxAliasPtr)
                : JitValueBox::valuePtrFromVariable($context, $arg);

            return $context->builder->call(
                $context->lookupFunction('__value__readDouble'),
                $valuePtr
            );
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type || JITVariable::TYPE_NATIVE_BOOL === $arg->type) {
            $lng = $context->helper->loadValue($arg);

            return $context->builder->siToFp($lng, $context->getTypeFromString('double'));
        }

        return $context->getTypeFromString('double')->constReal(0.0);
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
            case JITVariable::TYPE_NULL:
                return $context->getTypeFromString('int64')->constInt(0, false);
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
                // Lazy ±/×/`/` overflow (#37051): loadValue is the dummy i64 0 on the
                // overflow arm — materialize to a long/double __value__ so non-const
                // printf/sprintf (%b/%d/…) via __compiler_sprintf match Zend (#36386).
                if (
                    null !== $arg->longArithOverflowFlag
                    && (null !== $arg->longArithOverflowDoubleSlot
                        || null !== $arg->longArithOverflowPromoted)
                ) {
                    self::writeArg(
                        $context,
                        $slot,
                        JitLongArithOverflow::materializeOverflowableNativeLong($context, $arg)
                    );

                    return;
                }
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
