<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT ReflectionClass::{getInterfaces,getTraits} (#34121).
 *
 * Name → map<string, ReflectionClass> from {@see Type\Object_} interface/trait
 * tables (peer {@see ReflectionClassNameListRuntime} / getMethods #34107).
 * Unknown / internal → empty array.
 *
 * Each distinct name-list is materialized once into a module global (lazy), then
 * {@see JitValueBox::copyFromPointer} — fresh per-call setStringKeyObject maps
 * corrupted earlier results when two proxies ran in one function.
 *
 * php-src: zim_ReflectionClass_getInterfaces / getTraits
 */
final class ReflectionClassClassMapRuntime
{
    private const MAX_NAME_LEN = 512;

    private static int $emitSeq = 0;

    /** @var array<string, true> */
    private const KINDS = [
        'interfaces' => true,
        'traits' => true,
    ];

    /**
     * Per-module lazy globals (key: moduleOid."\\0".cacheKey).
     *
     * @var array<string, Value>
     */
    private static array $classMapGlobals = [];

    /**
     * @return Value __value__* result slot (name => ReflectionClass)
     */
    public static function emit(
        Context $context,
        string $kindLc,
        Value $nameCstr,
        Value $nameLen
    ): Value {
        $kindLc = strtolower($kindLc);
        if (!isset(self::KINDS[$kindLc])) {
            throw new \InvalidArgumentException('Unknown ReflectionClass class-map kind: '.$kindLc);
        }

        LibcExtern::ensureMemcmpDecl($context);

        $resultSlot = JitValueBox::alloc($context);
        $fn = BasicBlockHelper::parentFunction($context);
        $seq = (string) ++self::$emitSeq;
        $tag = 'refl_cm_'.$kindLc.'_'.$seq;
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
        foreach (self::classLcToNames($context, $kindLc) as $lcName => $names) {
            $wantLenInt = \strlen($lcName);
            if (0 === $wantLenInt) {
                continue;
            }

            $matchBlock = $fn->appendBasicBlock($tag.'_hit_'.$hitIdx);
            $nextCheck = $fn->appendBasicBlock($tag.'_try_'.$hitIdx);
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
            self::storeClassMap($context, $resultSlot, $names, $tag.'_s'.$hitIdx);
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
     * @return array<string, list<string>> lowercase class → display names
     */
    private static function classLcToNames(Context $context, string $kindLc): array
    {
        $pairs = [];
        $object = $context->type->object;
        foreach ($object->allClassNamesById() as $id => $className) {
            $id = (int) $id;
            $display = $object->classNameForId($id);
            if (!\is_string($display) || '' === $display) {
                $display = \is_string($className) ? $className : '';
            }
            $lc = strtolower(ltrim($display, '\\'));
            if ('' === $lc) {
                continue;
            }
            if ('interfaces' === $kindLc) {
                $names = [];
                foreach ($object->interfacesForClassImplementsLc($lc) as $ifaceLc) {
                    $ifaceLc = strtolower(ltrim((string) $ifaceLc, '\\'));
                    if ('' === $ifaceLc) {
                        continue;
                    }
                    $ifaceDisplay = $ifaceLc;
                    foreach ($object->allClassNamesById() as $iid => $iname) {
                        $iid = (int) $iid;
                        $idisp = $object->classNameForId($iid);
                        if (!\is_string($idisp) || '' === $idisp) {
                            $idisp = \is_string($iname) ? $iname : '';
                        }
                        if (strtolower(ltrim($idisp, '\\')) === $ifaceLc) {
                            $ifaceDisplay = $idisp;
                            break;
                        }
                    }
                    $names[] = $ifaceDisplay;
                }
                $pairs[$lc] = $names;
            } else {
                $pairs[$lc] = array_values($object->usedTraitNamesForClassLc($lc));
            }
        }
        ksort($pairs);

        return $pairs;
    }

    /**
     * Lazy module-global map; copy into {@see $resultSlot} (peer NameList globals).
     *
     * @param list<string> $names
     */
    private static function storeClassMap(
        Context $context,
        Value $resultSlot,
        array $names,
        string $tag
    ): void {
        if ([] === $names) {
            self::storeEmptyArray($context, $resultSlot);

            return;
        }

        $cacheKey = 'refl_cm_obj_'.md5(implode("\0", $names));
        $globalKey = spl_object_id($context->module)."\0".$cacheKey;
        if (!isset(self::$classMapGlobals[$globalKey])) {
            $ptrTy = $context->getTypeFromString('__value__*');
            $global = $context->module->addGlobal(
                $ptrTy,
                'refl_cm_obj_'.\count(self::$classMapGlobals).'_main'
            );
            $global->setInitializer($ptrTy->constNull());
            self::$classMapGlobals[$globalKey] = $global;
        }
        $global = self::$classMapGlobals[$globalKey];

        $fn = BasicBlockHelper::parentFunction($context);
        $needBuild = $fn->appendBasicBlock($tag.'_need');
        $have = $fn->appendBasicBlock($tag.'_have');
        $done = $fn->appendBasicBlock($tag.'_done');

        $cur = $context->builder->load($global);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $cur,
            $cur->typeOf()->constNull()
        );
        $context->builder->branchIf($isNull, $needBuild, $have);

        $context->builder->positionAtEnd($needBuild);
        $object = $context->type->object;
        $ht = HashTableHelper::alloc($context);
        $rcClassId = $object->lookup('ReflectionClass');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        foreach ($names as $name) {
            $rcObj = $object->allocate($rcClassId);
            ReflectionSetup::markConstructed($context, $rcObj);
            $nameGlobal = $context->constantStringFromString($name);
            $nameStr = $context->builder->load($nameGlobal);
            $strMap = $context->structFieldMap['__string__'];
            $cstr = $context->builder->pointerCast(
                $context->builder->structGep($nameStr, $strMap['value']),
                $i8p
            );
            $len = $context->builder->load(
                $context->builder->structGep($nameStr, $strMap['length'])
            );
            $lenSz = $context->builder->zExt($len, $sizeT);
            // Owned key via __string__init — do not share constantString globals as HT keys.
            $ownedKey = $context->builder->call(
                $context->lookupFunction('__string__init'),
                $context->builder->zExt($len, $context->getTypeFromString('int64')),
                $cstr
            );
            ReflectionSetup::emitSetStringPropertyFromCstr(
                $context,
                $rcObj,
                'ReflectionClass',
                ReflectionSupport::PROP_CLASS_NAME,
                $cstr,
                $lenSz
            );
            HashTableHelper::setAtStringKey(
                $context,
                $ht,
                $ownedKey,
                new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $rcObj)
            );
        }
        $context->refcount->addref($ht);
        $valueType = $context->getTypeFromString('__value__');
        $heapVal = $context->memory->malloc($valueType);
        $heapPtr = $context->builder->pointerCast(
            $heapVal,
            $context->getTypeFromString('__value__*')
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $heapPtr,
            $ht
        );
        $context->builder->store($heapPtr, $global);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($have);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        JitValueBox::copyFromPointer(
            $context,
            $resultSlot,
            $context->builder->load($global)
        );
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
