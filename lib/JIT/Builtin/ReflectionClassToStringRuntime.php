<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT ReflectionClass::__toString (#34135).
 *
 * Name → Zend `_class_string` dump baked from compile-time
 * {@see Context::$runtime} vmContext ClassEntries (peer VM
 * {@see ReflectionSupport::classReflectionToString} / #22379).
 *
 * Unknown name → empty string (avoids convert-to-string fatal; callers that
 * only reflect script-declared / registered classes match Zend).
 *
 * php-src: zim_ReflectionClass___toString / _class_string
 */
final class ReflectionClassToStringRuntime
{
    private const MAX_NAME_LEN = 512;

    /**
     * @return Value __value__* result slot (string)
     */
    public static function emit(
        Context $context,
        Value $nameCstr,
        Value $nameLen
    ): Value {
        LibcExtern::ensureMemcmpDecl($context);

        $resultSlot = JitValueBox::alloc($context);
        $fn = BasicBlockHelper::parentFunction($context);
        $tag = 'refl_cts';
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
        foreach (self::classLcToDump($context) as $lcName => $dump) {
            $wantLenInt = \strlen($lcName);
            if (0 === $wantLenInt || '' === $dump) {
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
            $dumpStr = $context->builder->load($context->constantStringFromString($dump));
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                JitValueBox::pointer($context, $resultSlot),
                $dumpStr
            );
            $context->builder->branch($merge);

            $checkBlock = $nextCheck;
            ++$hitIdx;
        }

        $context->builder->positionAtEnd($checkBlock);
        $context->builder->branch($miss);

        $context->builder->positionAtEnd($miss);
        $empty = $context->builder->load($context->constantStringFromString(''));
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $resultSlot),
            $empty
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $resultSlot;
    }

    /**
     * @return array<string, string> lowercase class → __toString dump
     */
    private static function classLcToDump(Context $context): array
    {
        $pairs = [];
        $vmCtx = $context->runtime->vmContext ?? null;
        if (null === $vmCtx) {
            return $pairs;
        }

        $object = $context->type->object;
        /** @var array<string, string> $names lc => display */
        $names = [];
        /** @var array<string, int> $objectIds lc => class id */
        $objectIds = [];
        foreach ($object->allClassNamesById() as $id => $className) {
            $id = (int) $id;
            $display = $object->classNameForId($id);
            if (!\is_string($display) || '' === $display) {
                $display = \is_string($className) ? $className : '';
            }
            if ('' === $display) {
                continue;
            }
            $lc = strtolower(ltrim($display, '\\'));
            $names[$lc] = $display;
            $objectIds[$lc] = $id;
        }
        foreach (['stdclass' => 'stdClass', 'exception' => 'Exception', 'closure' => 'Closure'] as $lc => $display) {
            if (!isset($names[$lc]) && isset($vmCtx->classes[$lc])) {
                $names[$lc] = $display;
            }
        }

        foreach ($names as $lc => $display) {
            $created = false;
            if (!isset($vmCtx->classes[$lc]) && isset($objectIds[$lc])) {
                $entry = new ClassEntry($display);
                $entry->isInternal = false;
                $loc = $object->classSourceLocation($objectIds[$lc]);
                if (null !== $loc) {
                    $entry->sourceLocation = $loc;
                }
                $vmCtx->classes[$lc] = $entry;
                $created = true;
            }
            try {
                $rc = ReflectionSupport::newReflectionClassObjectForName($vmCtx, $display);
                $pairs[$lc] = ReflectionSupport::classReflectionToString($vmCtx, $rc);
            } catch (\Throwable) {
                continue;
            } finally {
                if ($created) {
                    unset($vmCtx->classes[$lc]);
                }
            }
        }
        ksort($pairs);

        return $pairs;
    }
}
