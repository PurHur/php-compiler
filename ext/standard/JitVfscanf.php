<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Sscanf;
use PHPCompiler\JIT\Builtin\SscanfStrtolApply;
use PHPCompiler\JIT\Builtin\StreamReadRuntime;
use PHPCompiler\JIT\Builtin\SscanfFgetsThinArray;
use PHPCompiler\JIT\Builtin\StringSscanfArray;
use PHPCompiler\JIT\Builtin\StringSscanfByRef;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for vfscanf() / fscanf() (issue #6174, #27663).
 *
 * Thin AOT + compile-time "%d" formats: fgets + {@see SscanfStrtolApply} (no NestedJIT).
 * NestedJIT parseAssignMeta aborts on libc-fgets {@see __string__init} inputs (#27663).
 * Other formats: fgets + {@see __compiler_sscanf} (embed JIT / non-strtol formats).
 * php-src: ext/standard/file.c fscanf → php_stream_get_line + php_sscanf_internal
 */
final class JitVfscanf
{
    public static function parse(Context $context, string $function, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2) {
            throw new \LogicException($function.'() expects at least two arguments');
        }

        $handleLit = $args[0]->compileTimeLong ?? null;
        // $format soft-null on 8.4 (Zend DEP+coerce; #30236, ext/standard/file.c Z_PARAM_STR).
        $fmtLit = $args[1]->compileTimeString ?? null;
        if (($args[1]->isNullConstant ?? false) && null === $fmtLit) {
            if ($context->callerStrictTypes) {
                $fmtLit = null;
            } else {
                $fmtLit = '';
            }
        }
        if (null !== $handleLit && null !== $fmtLit && self::canFoldCompileTime($fmtLit, $argc - 2)) {
            return self::parseCompileTime($context, (int) $handleLit, $fmtLit, \array_slice($args, 2));
        }

        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], $function.'() stream'),
            $i64
        );

        return self::parseFromHandle(
            $context,
            $function,
            $handle,
            $args[1],
            $fmtLit,
            \array_slice($args, 2)
        );
    }

    /**
     * fscanf/vfscanf from a live stream-handle LLVM value (SplFileObject::__spl_fd, #33382).
     * Array-return (no by-ref outs) uses fgets + {@see __compiler_sscanf_array}.
     *
     * @param list<JITVariable> $outArgs
     */
    public static function parseFromHandle(
        Context $context,
        string $function,
        Value $handle,
        JITVariable $formatArg,
        ?string $fmtLit,
        array $outArgs,
        int $formatArgIndex = 1
    ): Value {
        $fmt = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $formatArg, $function, $formatArgIndex, 'format')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $formatArg, $function, $formatArgIndex, 'format');
        // declare(strict_types=1): null format rejects via lowerStrictOrCoercible — do not continue (#30236).
        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $formatArg->type || ($formatArg->isNullConstant ?? false))
        ) {
            return $context->getTypeFromString('__value__*')->constNull();
        }

        $outCount = \count($outArgs);
        if (0 === $outCount) {
            return self::parseArrayFromHandle($context, $handle, $fmt, $fmtLit);
        }

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $useStrtol = null !== $fmtLit
            && SscanfStrtolApply::isStrtolOnlyFormat($fmtLit)
            && $context->isThinStandaloneAotMain();

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        if ($useStrtol) {
            SscanfStrtolApply::ensureLinked($context);
        } else {
            Sscanf::ensureLinked($context);
            StringSscanfByRef::ensureLinked($context);
        }
        if ($context->isThinStandaloneAotMain()) {
            StreamReadRuntime::forceLibcStreamPositionAbis($context);
        } else {
            StreamReadRuntime::ensureVfscanfAbi($context);
        }
        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        }

        $ptrTy = $context->getTypeFromString('__value__*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $elemSize = $context->builder->ptrToInt(
            $context->builder->gep($ptrTy->pointerType(0)->constNull(), $i32->constInt(1, false)),
            $sizeT
        );
        $raw = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $context->builder->mul($elemSize, $context->builder->intCast($i64->constInt($outCount, false), $sizeT))
        );
        $outPtrs = $context->builder->pointerCast($raw, $context->getTypeFromString('__value__**'));
        for ($i = 0; $i < $outCount; ++$i) {
            $slot = $context->builder->inBoundsGEP(
                $outPtrs,
                $i64->constInt($i, false)
            );
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $outArgs[$i]);
            $context->builder->store($valuePtr, $slot);
        }

        $id = (string) \spl_object_id($context);
        $failBb = BasicBlockHelper::append($context, 'vfscanf_fgets_fail_'.$id);
        $nonNullBb = BasicBlockHelper::append($context, 'vfscanf_fgets_ok_'.$id);
        $scanBb = BasicBlockHelper::append($context, 'vfscanf_scan_'.$id);
        $doneBb = BasicBlockHelper::append($context, 'vfscanf_fgets_done_'.$id);
        $countSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $minusOne = $i64->constInt(-1, true);

        $line = $context->builder->call(
            $context->lookupFunction('__compiler_fgets'),
            $handle,
            $i64->constInt(-1, true)
        );
        $lineNull = $context->builder->icmp(Builder::INT_EQ, $line, $strPtr->constNull());
        $context->builder->branchIf($lineNull, $failBb, $nonNullBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->store($minusOne, $countSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($nonNullBb);
        $lineLen = $context->builder->call($context->lookupFunction('__string__strlen'), $line);
        $empty = $context->builder->icmp(Builder::INT_EQ, $lineLen, $i64->constInt(0, false));
        $context->builder->branchIf($empty, $failBb, $scanBb);

        $context->builder->positionAtEnd($scanBb);
        // libc fgets strings need a NestedJIT-safe copy before __compiler_sscanf (#27663 / #33382).
        $scanLine = $useStrtol ? $line : self::copyStringForNestedJit($context, $line);
        if ($useStrtol) {
            $assigned = $context->builder->call(
                $context->lookupFunction('phpc_sscanf_strtol_assign'),
                $scanLine,
                $i64->constInt($outCount, false),
                $outPtrs
            );
        } else {
            $assigned = $context->builder->call(
                $context->lookupFunction('__compiler_sscanf'),
                $scanLine,
                $fmt,
                $i64->constInt($outCount, false),
                $outPtrs
            );
        }
        $context->builder->store($assigned, $countSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $count = $context->builder->load($countSlot);
        $context->builder->call($context->lookupFunction('__mm__free'), $raw);

        return self::boxAssignedCount($context, $count);
    }

    /**
     * Two-arg fscanf array return from a live handle (#33382 / #9284).
     * EOF (fgets null) → null (Zend SplFileObject::fscanf); otherwise
     * NestedJIT-safe line copy + {@see __compiler_sscanf_array}.
     */
    public static function parseArrayFromHandle(
        Context $context,
        Value $handle,
        Value $fmt,
        ?string $fmtLit = null
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        Sscanf::ensureLinked($context);
        $useThin = null !== $fmtLit && SscanfFgetsThinArray::supportsFormat($fmtLit);
        if (!$useThin) {
            StringSscanfArray::ensureLinked($context);
        }
        if ($context->isThinStandaloneAotMain()) {
            StreamReadRuntime::forceLibcStreamPositionAbis($context);
        } else {
            StreamReadRuntime::ensureVfscanfAbi($context);
        }
        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        }

        $id = (string) \spl_object_id($context);
        $failBb = BasicBlockHelper::append($context, 'vfscanf_arr_fgets_fail_'.$id);
        $scanBb = BasicBlockHelper::append($context, 'vfscanf_arr_scan_'.$id);
        $doneBb = BasicBlockHelper::append($context, 'vfscanf_arr_done_'.$id);
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));

        $line = $context->builder->call(
            $context->lookupFunction('__compiler_fgets'),
            $handle,
            $i64->constInt(-1, true)
        );
        $lineNull = $context->builder->icmp(Builder::INT_EQ, $line, $strPtr->constNull());
        $context->builder->branchIf($lineNull, $failBb, $scanBb);

        $context->builder->positionAtEnd($failBb);
        $eofSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $eofSlot)
        );
        $context->builder->store(JitValueBox::pointer($context, $eofSlot), $resultSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($scanBb);
        if ($useThin) {
            $boxed = SscanfFgetsThinArray::scanToArrayBox($context, $line, $fmtLit);
            $context->builder->store($boxed, $resultSlot);
        } else {
            $safeLine = self::copyStringForNestedJit($context, $line);
            $scanned = $context->builder->call(
                $context->lookupFunction('__compiler_sscanf_array'),
                $safeLine,
                $fmt
            );
            $context->builder->store($scanned, $resultSlot);
        }
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $context->builder->load($resultSlot);
    }

    /**
     * Copy {@see __string__*} bytes into a fresh {@see __string__init} for NestedJIT (#24137 / #27663).
     */
    private static function copyStringForNestedJit(Context $context, Value $payload): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $separated = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $payload
        );
        $slot = BasicBlockHelper::entryAlloca($context, $strPtr);
        $context->builder->store($separated, $slot);
        $loaded = $context->builder->load($slot);
        $map = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $len = $context->builder->call($context->lookupFunction('__string__strlen'), $loaded);
        $src = $context->builder->pointerCast(
            $context->builder->structGep($loaded, $map['value']),
            $i8p
        );
        $copy = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $src
        );
        $context->refcount->disableRefcount($copy);

        return $copy;
    }

    private static function boxAssignedCount(Context $context, Value $raw): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $falseSentinel = $i64->constInt(-1, true);
        $isFalse = $context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $raw, $falseSentinel);

        $id = (string) \spl_object_id($context);
        $failBlock = BasicBlockHelper::append($context, 'vfscanf_false_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'vfscanf_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'vfscanf_done_'.$id);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->branchIf($isFalse, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeLong($context, $slot, $raw);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /** Compile-time fold only when arity is valid — mismatches use runtime LLVM (#4064). */
    private static function canFoldCompileTime(string $format, int $outCount): bool
    {
        if (0 === $outCount) {
            return true;
        }
        try {
            VmSscanf::validateOutVarArity($format, $outCount);

            return true;
        } catch (\ValueError) {
            return false;
        }
    }

    /**
     * @param list<JITVariable> $outArgs
     */
    private static function parseCompileTime(Context $context, int $handle, string $format, array $outArgs): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if ([] === $outArgs) {
            $ht = VmVfscanf::parseToArray($handle, $format);
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            if (false === $ht) {
                $context->builder->call(
                    $context->lookupFunction('__value__writeBool'),
                    $ptr,
                    $context->getTypeFromString('int32')->constInt(0, false)
                );
            } elseif (null === $ht) {
                $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
            } else {
                $htVar = JitSscanf::materializeVmHashTable($context, $ht);
                $context->builder->call(
                    $context->lookupFunction('__value__writeHashtable'),
                    $ptr,
                    HashTableHelper::loadHashtablePointer($context, $htVar)
                );
            }

            return $ptr;
        }

        $temps = [];
        foreach ($outArgs as $_) {
            $temps[] = new VMVariable();
        }
        $assigned = VmVfscanf::parse($handle, $format, $temps);
        if (false === $assigned) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $context->builder->call(
                $context->lookupFunction('__value__writeBool'),
                $ptr,
                $context->getTypeFromString('int32')->constInt(0, false)
            );

            return $ptr;
        }
        for ($i = 0; $i < $assigned; ++$i) {
            JitSscanf::writeVmVarToOut($context, $outArgs[$i], $temps[$i]);
        }

        return $i64->constInt($assigned, false);
    }
}
