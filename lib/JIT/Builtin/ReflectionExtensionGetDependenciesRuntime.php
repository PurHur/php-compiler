<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmReflection;
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
 * Thin-AOT ReflectionExtension::getDependencies() (#34155).
 *
 * Extension name → assoc string[] of dependency ⇒ Required|Optional|Conflicts,
 * or empty array when the extension has no deps / is unknown.
 *
 * Bakes {@see VmReflection::reflectionExtensionDependenciesTable} (#22247).
 * Peer memcmp tables: {@see ReflectionExtensionGetClassNamesRuntime} (#34150).
 *
 * php-src: zim_ReflectionExtension_getDependencies
 */
final class ReflectionExtensionGetDependenciesRuntime
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
        $tag = 'refl_ext_gdep';
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
        foreach (self::extensionLcToDeps() as $lcExt => $deps) {
            $wantLenInt = \strlen($lcExt);
            if (0 === $wantLenInt || [] === $deps) {
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
            self::storeDepsMap($context, $resultSlot, $lcExt, $deps);
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
     * @return array<string, array<string, string>> lowercase extension → dep ⇒ relation
     */
    private static function extensionLcToDeps(): array
    {
        /** @var array<string, array<string, string>> $out */
        $out = [];
        // Keys mirror VmReflection::EXTENSION_DEPENDENCIES (#22247); unknown → empty via miss.
        foreach ([
            'standard',
            'spl',
            'session',
            'sodium',
            'pdo',
            'xml',
            'dom',
            'mbstring',
            'simplexml',
            'exif',
            'phar',
        ] as $ext) {
            $ht = VmReflection::reflectionExtensionDependenciesTable($ext);
            $map = [];
            foreach ($ht->exportKeyValuePairs() as [$keyVar, $valVar]) {
                $map[$keyVar->toString()] = $valVar->toString();
            }
            if ([] !== $map) {
                $out[strtolower($ext)] = $map;
            }
        }
        ksort($out);

        return $out;
    }

    /** @param array<string, string> $deps */
    private static function storeDepsMap(
        Context $context,
        Value $resultSlot,
        string $lcExt,
        array $deps
    ): void {
        if ([] === $deps) {
            self::storeEmptyArray($context, $resultSlot);

            return;
        }
        $ht = new HashTable();
        foreach ($deps as $name => $relation) {
            $slot = new VMVariable();
            $slot->string($relation);
            $ht->add($name, $slot);
        }
        $parts = [];
        foreach ($deps as $name => $relation) {
            $parts[] = $name.'='.$relation;
        }
        sort($parts, \SORT_STRING);
        $cacheKey = 'refl_ext_gdep_'.md5($lcExt.'|'.implode("\0", $parts));
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
