<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\VM\ClassEntry;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT ReflectionClass::getExtensionName() (#34139).
 *
 * Name (from ReflectionClass) → extension string for internal classes, or bool
 * false for user / unknown (php-src zim_ReflectionClass_getExtensionName /
 * {@see \PHPCompiler\VM\ReflectionSupport::returnExtensionName}).
 *
 * Must not use {@see Type\Object_::classIdFromRuntimeName} — that aborts on
 * names absent from the JIT class table. Peer: name memcmp tables in
 * {@see ReflectionClassGetFileNameRuntime} / {@see ReflectionClassKindNameTableRuntime}.
 *
 * Extension names come from VM ClassEntry + {@see VmReflection::extensionNameForInternalClass}.
 */
final class ReflectionClassGetExtensionNameRuntime
{
    private const MAX_NAME_LEN = 512;

    /**
     * @return Value __value__* result slot (string|false)
     */
    public static function emit(Context $context, Value $nameCstr, Value $nameLen): Value
    {
        LibcExtern::ensureMemcmpDecl($context);

        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        $fn = BasicBlockHelper::parentFunction($context);
        $tag = 'refl_getextname';
        $entry = $context->builder->getInsertBlock();
        $merge = $fn->appendBasicBlock($tag.'_merge');
        $miss = $fn->appendBasicBlock($tag.'_miss');
        $fold = $fn->appendBasicBlock($tag.'_fold');

        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $charPtr = $context->getTypeFromString('char*');

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
        foreach (self::classLcToExtension($context) as $lcName => $extName) {
            $wantLenInt = \strlen($lcName);
            if (0 === $wantLenInt || '' === $extName) {
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
            $str = $context->builder->call(
                $context->lookupFunction('__string__init'),
                $i64->constInt(\strlen($extName), false),
                $context->builder->pointerCast(
                    $context->constantFromString($extName),
                    $charPtr
                )
            );
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $resultPtr,
                $str
            );
            $context->builder->branch($merge);

            $checkBlock = $nextCheck;
            ++$hitIdx;
        }

        $context->builder->positionAtEnd($checkBlock);
        $context->builder->branch($miss);

        $context->builder->positionAtEnd($miss);
        JitValueBox::writeBool(
            $context,
            $resultSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $resultSlot;
    }

    /**
     * @return array<string, string> lowercase class → extension display name
     */
    private static function classLcToExtension(Context $context): array
    {
        /** @var array<string, string> $pairs */
        $pairs = [];
        $vmCtx = $context->runtime->vmContext ?? null;
        if (null === $vmCtx || !\is_array($vmCtx->classes ?? null)) {
            return $pairs;
        }

        foreach ($vmCtx->classes as $entry) {
            if (!$entry instanceof ClassEntry || !$entry->isInternal) {
                continue;
            }
            $lc = strtolower(ltrim($entry->name, '\\'));
            if ('' === $lc) {
                continue;
            }
            $pairs[$lc] = VmReflection::extensionNameForInternalClass($entry->name);
        }
        ksort($pairs);

        return $pairs;
    }
}
