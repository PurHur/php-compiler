<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT ReflectionClass::getExtension() (#34145).
 *
 * Name (from ReflectionClass) → constructed ReflectionExtension for internal
 * classes, or null for user / unknown (php-src zim_ReflectionClass_getExtension /
 * {@see ReflectionSupport::returnExtension}).
 *
 * Peer: {@see ReflectionClassGetExtensionNameRuntime} (#34139) /
 * {@see ReflectionClassGetParentClass} object allocation (#34069).
 */
final class ReflectionClassGetExtensionRuntime
{
    private const MAX_NAME_LEN = 512;

    /**
     * @return Value __value__* result slot (?ReflectionExtension)
     */
    public static function emit(Context $context, Value $nameCstr, Value $nameLen): Value
    {
        LibcExtern::ensureMemcmpDecl($context);

        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        $fn = BasicBlockHelper::parentFunction($context);
        $tag = 'refl_getextension';
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

        $reClassId = $context->type->object->lookup('ReflectionExtension');
        $checkBlock = $afterFold;
        $hitIdx = 0;
        foreach (self::internalExtensionPairs($context) as [$lcName, $extName]) {
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
            $reObj = $context->type->object->allocate($reClassId);
            $extCstr = $context->builder->pointerCast(
                $context->constantFromString($extName),
                $i8p
            );
            ReflectionSetup::emitSetStringPropertyFromCstr(
                $context,
                $reObj,
                'ReflectionExtension',
                ReflectionSupport::PROP_EXTENSION_NAME,
                $extCstr,
                $sizeT->constInt(\strlen($extName), false)
            );
            ReflectionSetup::markConstructed($context, $reObj);
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                $resultPtr,
                $reObj
            );
            $context->builder->branch($merge);

            $checkBlock = $nextCheck;
            ++$hitIdx;
        }

        $context->builder->positionAtEnd($checkBlock);
        $context->builder->branch($miss);

        $context->builder->positionAtEnd($miss);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $resultPtr
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $resultSlot;
    }

    /**
     * @return list<array{0: string, 1: string}> lowercase class name → extension name
     */
    private static function internalExtensionPairs(Context $context): array
    {
        /** @var array<string, string> $byLc */
        $byLc = [];
        $vmCtx = $context->runtime->vmContext ?? null;
        if (null !== $vmCtx && \is_array($vmCtx->classes ?? null)) {
            foreach ($vmCtx->classes as $entry) {
                if (!$entry instanceof ClassEntry || !$entry->isInternal) {
                    continue;
                }
                $lc = strtolower(ltrim((string) $entry->name, '\\'));
                if ('' === $lc) {
                    continue;
                }
                $byLc[$lc] = VmReflection::extensionNameForInternalClass($entry->name);
            }
        }
        $pairs = [];
        foreach ($byLc as $lc => $ext) {
            $pairs[] = [$lc, $ext];
        }
        usort($pairs, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $pairs;
    }
}
