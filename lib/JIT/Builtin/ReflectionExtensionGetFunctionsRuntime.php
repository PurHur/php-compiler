<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\ext\standard\VmReflection;
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
 * Thin-AOT ReflectionExtension::getFunctions() (#34177).
 *
 * Extension name → name-indexed ReflectionFunction map, or empty array when unknown.
 *
 * Peer memcmp tables: {@see ReflectionExtensionGetClassNamesRuntime} (#34150);
 * object alloc peer getClasses (#34169); VM functions table.
 * VM: {@see \PHPCompiler\VM\Builtin\ReflectionExtensionGetFunctions} /
 * {@see VmReflection::reflectionExtensionFunctionsTable} (#18326).
 *
 * php-src: zim_ReflectionExtension_getFunctions
 */
final class ReflectionExtensionGetFunctionsRuntime
{
    private const MAX_NAME_LEN = 512;

    /**
     * @return Value __value__* result slot (array of ReflectionFunction)
     */
    public static function emit(Context $context, Value $nameCstr, Value $nameLen): Value
    {
        LibcExtern::ensureMemcmpDecl($context);

        $resultSlot = JitValueBox::alloc($context);
        $fn = BasicBlockHelper::parentFunction($context);
        $tag = 'refl_ext_gfls';
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
        foreach (self::extensionLcToFunctionNames($context) as $lcExt => $names) {
            $wantLenInt = \strlen($lcExt);
            if (0 === $wantLenInt) {
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
            self::storeFunctionMap($context, $resultSlot, $names);
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
     * @return array<string, list<string>> lowercase extension → function names
     */
    private static function extensionLcToFunctionNames(Context $context): array
    {
        /** @var array<string, list<string>> $byExt */
        $byExt = [];
        /** @var array<string, true> $allNames */
        $allNames = [];
        foreach (ModuleRegistry::extensionFunctionMap() as $funcs) {
            if (!is_array($funcs)) {
                continue;
            }
            foreach ($funcs as $name) {
                $name = (string) $name;
                if ('' !== $name) {
                    $allNames[$name] = true;
                }
            }
        }
        foreach (ModuleRegistry::extensionFunctionMap() as $ext => $_) {
            $lcExt = strtolower((string) $ext);
            if ('' === $lcExt) {
                continue;
            }
            foreach (ModuleRegistry::getExtensionFunctions($lcExt) ?? [] as $name) {
                $name = (string) $name;
                if ('' !== $name) {
                    $allNames[$name] = true;
                }
            }
        }
        // Group by reflection-owning extension, not registration bucket (#34197).
        // VM peer: {@see VmReflection::reflectionExtensionFunctionsTable}.
        foreach (array_keys($allNames) as $name) {
            $ownerLc = strtolower(ModuleRegistry::reflectionOwningExtension(strtolower($name)));
            if ('' === $ownerLc) {
                continue;
            }
            if (!VmReflection::functionIsVisibleInReflection($name, $ownerLc)) {
                continue;
            }
            $byExt[$ownerLc][] = $name;
        }
        foreach ($byExt as $lcExt => $names) {
            $byExt[$lcExt] = array_values(array_unique($names));
            sort($byExt[$lcExt], SORT_STRING);
        }
        ksort($byExt);

        return $byExt;
    }

    private static function storeFunctionMap(Context $context, Value $resultSlot, array $names): void
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
        $rcClassId = $object->lookup('ReflectionFunction');
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
                'ReflectionFunction',
                ReflectionSupport::PROP_REFLECTION_FUNCTION_NAME,
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
