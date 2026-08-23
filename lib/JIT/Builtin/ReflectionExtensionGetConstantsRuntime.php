<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\ExtensionConstantGroups;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT ReflectionExtension::getConstants() (#34162).
 *
 * Extension name → assoc constant map (int|float|bool|string values),
 * or empty array when unknown. Bakes {@see ExtensionConstantGroups::groups()}
 * fallbacks (same values {@see \PHPCompiler\ext\standard\VmReflection::reflectionExtensionConstantsTable}
 * uses when constantFetchBuiltin misses).
 *
 * Peer memcmp tables: {@see ReflectionExtensionGetDependenciesRuntime} (#34155).
 *
 * php-src: zim_ReflectionExtension_getConstants
 */
final class ReflectionExtensionGetConstantsRuntime
{
    private const MAX_NAME_LEN = 512;

    /**
     * @return Value __value__* result slot (array)
     */
    public static function emit(Context $context, Value $nameCstr, Value $nameLen): Value
    {
        LibcExtern::ensureMemcmpDecl($context);

        $resultSlot = JitValueBox::alloc($context);
        $fn = BasicBlockHelper::parentFunction($context);
        $tag = 'refl_ext_gconst';
        $entry = $context->builder->getInsertBlock();
        $merge = $fn->appendBasicBlock($tag.'_merge');
        $miss = $fn->appendBasicBlock($tag.'_miss');
        $fold = $fn->appendBasicBlock($tag.'_fold');

        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');

        $context->builder->positionAtEnd($entry);
        $buf = $context->builder->alloca($i8->arrayType(self::MAX_NAME_LEN));
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $tooLong = $context->builder->icmp(
            Builder::INT_UGT,
            $nameLen,
            $sizeT->constInt(self::MAX_NAME_LEN, false)
        );
        $context->builder->branchIf($tooLong, $miss, $fold);

        $context->builder->positionAtEnd($fold);
        $idxAlloca = $context->builder->alloca($sizeT);
        $context->builder->store($sizeT->constInt(0, false), $idxAlloca);
        $loop = $fn->appendBasicBlock($tag.'_fold_loop');
        $afterFold = $fn->appendBasicBlock($tag.'_after_fold');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxAlloca);
        $foldDone = $context->builder->icmp(Builder::INT_EQ, $idx, $nameLen);
        $body = $fn->appendBasicBlock($tag.'_fold_body');
        $context->builder->branchIf($foldDone, $afterFold, $body);

        $context->builder->positionAtEnd($body);
        $srcPtr = $context->builder->gep($nameCstr, $idx);
        $ch = $context->builder->load($srcPtr);
        $geA = $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(ord('A'), true));
        $leZ = $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(ord('Z'), true));
        $isUpper = $context->builder->and($geA, $leZ);
        $lowered = $context->builder->add($ch, $i8->constInt(32, true));
        $folded = $context->builder->select($isUpper, $lowered, $ch);
        $dstPtr = $context->builder->gep($bufPtr, $idx);
        $context->builder->store($folded, $dstPtr);
        $context->builder->store(
            $context->builder->add($idx, $sizeT->constInt(1, false)),
            $idxAlloca
        );
        $context->builder->branch($loop);

        $checkBlock = $afterFold;
        $hitIdx = 0;
        foreach (self::extensionLcToConstants() as $lcExt => $constants) {
            $wantLenInt = \strlen($lcExt);
            if (0 === $wantLenInt || [] === $constants) {
                continue;
            }

            $matchBlock = $fn->appendBasicBlock($tag.'_hit_'.$hitIdx);
            $nextCheck = $fn->appendBasicBlock($tag.'_try_'.$hitIdx);
            $context->builder->positionAtEnd($checkBlock);

            $wantLen = $sizeT->constInt($wantLenInt, false);
            $wantGlobal = $context->constantStringFromString($lcExt);
            $wantStr = $context->builder->load($wantGlobal);
            $strMap = $context->structFieldMap['__string__'];
            $wantCstr = $context->builder->pointerCast(
                $context->builder->structGep($wantStr, $strMap['value']),
                $i8p
            );
            $lenEq = $context->builder->icmp(Builder::INT_EQ, $nameLen, $wantLen);
            $cmp = $context->builder->call(
                $context->lookupFunction('memcmp'),
                $bufPtr,
                $wantCstr,
                $context->builder->zExt($wantLen, $i64)
            );
            $nameEq = $context->builder->icmp(
                Builder::INT_EQ,
                $cmp,
                $i32->constInt(0, false)
            );
            $match = $context->builder->and($lenEq, $nameEq);
            $context->builder->branchIf($match, $matchBlock, $nextCheck);

            $context->builder->positionAtEnd($matchBlock);
            self::storeConstantsMap($context, $resultSlot, $lcExt, $constants);
            $context->builder->branch($merge);

            $checkBlock = $nextCheck;
            ++$hitIdx;
        }

        $context->builder->positionAtEnd($checkBlock);
        $context->builder->branch($miss);

        $context->builder->positionAtEnd($miss);
        self::storeEmptyArray($context, $resultSlot);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $resultSlot;
    }

    /**
     * @return array<string, array<string, mixed>> lowercase extension → name → value
     */
    private static function extensionLcToConstants(): array
    {
        /** @var array<string, array<string, mixed>> $out */
        $out = [];
        foreach (ExtensionConstantGroups::groups() as $ext => $constants) {
            if (!\is_array($constants) || [] === $constants) {
                continue;
            }
            $lc = strtolower((string) $ext);
            if ('' === $lc) {
                continue;
            }
            $out[$lc] = $constants;
        }
        ksort($out);

        return $out;
    }

    /** @param array<string, mixed> $constants */
    private static function storeConstantsMap(
        Context $context,
        Value $resultSlot,
        string $lcExt,
        array $constants
    ): void {
        if ([] === $constants) {
            self::storeEmptyArray($context, $resultSlot);

            return;
        }
        $ht = new HashTable();
        $parts = [];
        foreach ($constants as $name => $fallback) {
            $slot = new VMVariable();
            if (\is_int($fallback)) {
                $slot->int($fallback);
                $parts[] = $name.'=i:'.$fallback;
            } elseif (\is_float($fallback)) {
                $slot->float($fallback);
                $parts[] = $name.'=f:'.$fallback;
            } elseif (\is_bool($fallback)) {
                $slot->bool($fallback);
                $parts[] = $name.'=b:'.($fallback ? '1' : '0');
            } else {
                $slot->string((string) $fallback);
                $parts[] = $name.'=s:'.(string) $fallback;
            }
            $ht->add((string) $name, $slot);
        }
        sort($parts, \SORT_STRING);
        $cacheKey = 'refl_ext_gconst_'.md5($lcExt.'|'.implode("\0", $parts));
        $global = $context->constantArrayFromVmHashTable($cacheKey, $ht);
        JitValueBox::copyFromPointer($context, $resultSlot, $context->builder->load($global));
    }

    private static function storeEmptyArray(Context $context, Value $resultSlot): void
    {
        $empty = ArrayBuiltinHelper::emptyArray($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            JitValueBox::pointer($context, $resultSlot),
            $empty
        );
    }
}
