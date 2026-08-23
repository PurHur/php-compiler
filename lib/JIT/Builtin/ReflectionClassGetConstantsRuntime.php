<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ClassConstVisibility;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT ReflectionClass::getConstants() (#34109, #6950).
 *
 * Name (from ReflectionClass) → HashTable of declared-name => constant value.
 * Walks parents; skips parent private constants (php-src reflection_class_get_constants).
 * Unknown / internal names (e.g. stdClass) → empty array — must not use
 * {@see Type\Object_::classIdFromRuntimeName} (that aborts on miss).
 *
 * Peer: {@see ReflectionClassGetFileNameRuntime}, {@see GetClassVarsRuntime}.
 */
final class ReflectionClassGetConstantsRuntime
{
    private const MAX_NAME_LEN = 512;

    /**
     * @return Value __value__* (array)
     */
    public static function emit(Context $context, Value $nameCstr, Value $nameLen, int $filter): Value
    {
        LibcExtern::ensureMemcmpDecl($context);

        $object = $context->type->object;
        $resultSlot = BasicBlockHelper::entryAlloca(
            $context,
            $context->getTypeFromString('__value__*')
        );
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $merge = $fn->appendBasicBlock('refl_getconstants_merge');
        $miss = $fn->appendBasicBlock('refl_getconstants_miss');
        $fold = $fn->appendBasicBlock('refl_getconstants_fold');

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
        $loop = $fn->appendBasicBlock('refl_getconstants_fold_loop');
        $afterFold = $fn->appendBasicBlock('refl_getconstants_after_fold');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxAlloca);
        $foldDone = $context->builder->icmp(Builder::INT_EQ, $idx, $nameLen);
        $body = $fn->appendBasicBlock('refl_getconstants_fold_body');
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
        foreach ($object->allClassNamesById() as $id => $className) {
            $id = (int) $id;
            if (!$object->hasUserDeclaredClass($className)) {
                continue;
            }
            $lcName = strtolower(ltrim((string) $className, '\\'));
            $wantLenInt = \strlen($lcName);
            if (0 === $wantLenInt) {
                continue;
            }

            $matchBlock = $fn->appendBasicBlock('refl_getconstants_hit_'.$hitIdx);
            $nextCheck = $fn->appendBasicBlock('refl_getconstants_try_'.$hitIdx);
            $context->builder->positionAtEnd($checkBlock);

            $wantLen = $sizeT->constInt($wantLenInt, false);
            $wantGlobal = $context->constantStringFromString($lcName);
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
            $boxed = self::emitConstantsHashTable($context, $id, $filter);
            $context->builder->store(
                JitValueBox::coerceToValuePtrForStore($context, $boxed),
                $resultSlot
            );
            $context->builder->branch($merge);

            $checkBlock = $nextCheck;
            ++$hitIdx;
        }

        $context->builder->positionAtEnd($checkBlock);
        $context->builder->branch($miss);

        $context->builder->positionAtEnd($miss);
        $empty = self::wrapHashTable($context, HashTableHelper::alloc($context));
        $context->builder->store(
            JitValueBox::coerceToValuePtrForStore($context, $empty),
            $resultSlot
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }

    /**
     * @return Value __value__* wrapping hashtable
     */
    private static function emitConstantsHashTable(Context $context, int $classId, int $filter): Value
    {
        $object = $context->type->object;
        $ht = HashTableHelper::alloc($context);
        /** @var array<string, true> $seen */
        $seen = [];
        $reflectedLc = strtolower(ltrim($object->classNameForId($classId), '\\'));
        $currentId = $classId;
        for ($depth = 0; $depth < 64; ++$depth) {
            foreach ($object->classConstantsForId($currentId) as [$key, $_entry]) {
                if (!\is_string($key) || '' === $key || isset($seen[$key])) {
                    continue;
                }
                $vis = ClassConstVisibility::mask($object->constVisibility($currentId, $key));
                $declLc = strtolower(ltrim($object->classNameForId($currentId), '\\'));
                if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0 && $declLc !== $reflectedLc) {
                    $seen[$key] = true;
                    continue;
                }
                if (!self::matchesFilter($vis, $filter)) {
                    continue;
                }
                $display = $object->classConstDisplayName($currentId, $key);
                $keyStr = $context->builder->load($context->constantStringFromString($display));
                $jit = $object->classConstFetch($classId, $display, null, $object->classNameForId($classId));
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);
                $seen[$key] = true;
            }
            $parentName = $object->parentClassDisplayName($object->classNameForId($currentId));
            if (null === $parentName) {
                break;
            }
            $currentId = $object->lookup($parentName);
        }

        return self::wrapHashTable($context, $ht);
    }

    private static function matchesFilter(int $cfgVisibility, int $filter): bool
    {
        if (0 === $filter) {
            return true;
        }
        if (($cfgVisibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
            $flags = \PHPCfg\Func::FLAG_PRIVATE;
        } elseif (($cfgVisibility & \PHPCfg\Func::FLAG_PROTECTED) !== 0) {
            $flags = \PHPCfg\Func::FLAG_PROTECTED;
        } else {
            $flags = \PHPCfg\Func::FLAG_PUBLIC;
        }

        return ($flags & $filter) !== 0;
    }

    private static function wrapHashTable(Context $context, Value $ht): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }
}
