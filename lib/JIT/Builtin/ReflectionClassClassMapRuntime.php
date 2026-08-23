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
 * Name → ReflectionClass map from {@see Type\Object_} interface/trait tables
 * (peer {@see ReflectionClassNameListRuntime} #34110). Unknown / internal → empty array.
 *
 * php-src: zim_ReflectionClass_getInterfaces / getTraits
 */
final class ReflectionClassClassMapRuntime
{
    private const MAX_NAME_LEN = 512;

    /** @var array<string, true> */
    private const KINDS = [
        'interfaces' => true,
        'traits' => true,
    ];

    /**
     * @return Value __value__* result slot (array of ReflectionClass)
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
        $tag = 'refl_cm_'.$kindLc;
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
            self::storeClassMap($context, $resultSlot, $names);
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

    /** @param list<string> $names */
    private static function storeClassMap(Context $context, Value $resultSlot, array $names): void
    {
        if ([] === $names) {
            self::storeEmptyArray($context, $resultSlot);

            return;
        }
        $object = $context->type->object;
        $ht = HashTableHelper::alloc($context);
        $n = \count($names);
        $context->builder->call(
            $context->lookupFunction('__hashtable__grow'),
            $ht,
            $context->constantFromInteger($n, 'size_t')
        );
        $rcClassId = $object->lookup('ReflectionClass');
        foreach ($names as $name) {
            $name = (string) $name;
            if ('' === $name) {
                continue;
            }
            $rcObj = $object->allocate($rcClassId);
            ReflectionSetup::markConstructed($context, $rcObj);
            $nameStr = $context->builder->load($context->constantStringFromString($name));
            ReflectionSetup::emitSetStringPropertyFromVar(
                $context,
                $rcObj,
                'ReflectionClass',
                ReflectionSupport::PROP_CLASS_NAME,
                new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $nameStr)
            );
            $keyStr = $context->builder->load($context->constantStringFromString($name));
            HashTableHelper::setAtStringKey(
                $context,
                $ht,
                $keyStr,
                new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $rcObj)
            );
        }
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            JitValueBox::pointer($context, $resultSlot),
            $ht
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
