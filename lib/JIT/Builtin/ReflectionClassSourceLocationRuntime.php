<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT ReflectionClass::{getStartLine,getEndLine,getDocComment} (#34106, #34186).
 *
 * Name (from ReflectionClass) → DECLARE_* {@see Type\Object_::classSourceLocation}
 * via lowercase memcmp (same pattern as {@see ReflectionClassGetFileNameRuntime}).
 * Miss / internal / empty → bool false (php-src zim_ReflectionClass_get*).
 *
 * Must not use {@see Type\Object_::classIdFromRuntimeName} — aborts on names
 * absent from the JIT class table (e.g. stdClass).
 *
 * Each emit gets a unique BB prefix (#34186): thrice-inlined getStartLine through a
 * typed is_array/is_object helper on trait classes must not reuse fixed labels or a
 * single stack result slot. Heap result + seq prefixes match peer NestedJIT helpers.
 */
final class ReflectionClassSourceLocationRuntime
{
    private const MAX_NAME_LEN = 512;

    private static int $emitSeq = 0;

    /**
     * @param 'startLine'|'endLine'|'docComment' $field
     *
     * @return Value __value__* result slot (int|false or string|false)
     */
    public static function emit(Context $context, Value $nameCstr, Value $nameLen, string $field): Value
    {
        if (!\in_array($field, ['startLine', 'endLine', 'docComment'], true)) {
            throw new \InvalidArgumentException(
                'ReflectionClassSourceLocationRuntime::emit: unknown field '.$field
            );
        }

        LibcExtern::ensureMemcmpDecl($context);

        $object = $context->type->object;
        $fn = BasicBlockHelper::parentFunction($context);
        // Heap result slot + unique BB prefixes per emit (#34186).
        $valueType = $context->getTypeFromString('__value__');
        $heapVal = $context->memory->malloc($valueType);
        $resultSlot = $context->builder->pointerCast(
            $heapVal,
            $context->getTypeFromString('__value__*')
        );
        $resultPtr = $resultSlot;
        $map = $context->structFieldMap['__value__'];
        $context->builder->store(
            $context->getTypeFromString('int8')->constInt(Variable::TYPE_NULL, false),
            $context->builder->structGep($resultSlot, $map['type'])
        );

        $prefix = 'refl_src_'.$field.'_'.(++self::$emitSeq);
        $entry = $context->builder->getInsertBlock();
        $merge = $fn->appendBasicBlock($prefix.'_merge');
        $miss = $fn->appendBasicBlock($prefix.'_miss');
        $fold = $fn->appendBasicBlock($prefix.'_fold');

        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $charPtr = $context->getTypeFromString('char*');

        $context->builder->positionAtEnd($entry);
        // Fold buffer in function entry — not mid-CFG alloca (#34186).
        $buf = BasicBlockHelper::entryAllocaForFunction(
            $context,
            $fn,
            $i8->arrayType(self::MAX_NAME_LEN)
        );
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
        $loop = $fn->appendBasicBlock($prefix.'_fold_loop');
        $afterFold = $fn->appendBasicBlock($prefix.'_after_fold');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxAlloca);
        $foldDone = $context->builder->icmp(Builder::INT_EQ, $idx, $nameLen);
        $body = $fn->appendBasicBlock($prefix.'_fold_body');
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
            $loc = $object->classSourceLocation($id);
            if (null === $loc) {
                continue;
            }
            $refl = $loc->forReflection();
            if ('docComment' === $field) {
                $doc = $refl->docComment;
                if (null === $doc || '' === $doc) {
                    continue;
                }
                $payload = $doc;
            } else {
                $line = 'startLine' === $field ? $refl->startLine : $refl->endLine;
                if ($line <= 0) {
                    continue;
                }
                $payload = $line;
            }

            $lcName = strtolower(ltrim((string) $className, '\\'));
            $wantLenInt = \strlen($lcName);
            if (0 === $wantLenInt) {
                continue;
            }

            $matchBlock = $fn->appendBasicBlock($prefix.'_hit_'.$hitIdx);
            $nextCheck = $fn->appendBasicBlock($prefix.'_try_'.$hitIdx);
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
            if ('docComment' === $field) {
                /** @var string $payload */
                $str = $context->builder->call(
                    $context->lookupFunction('__string__init'),
                    $i64->constInt(\strlen($payload), false),
                    $context->builder->pointerCast(
                        $context->constantFromString($payload),
                        $charPtr
                    )
                );
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $resultPtr,
                    $str
                );
            } else {
                /** @var int $payload */
                JitValueBox::writeLong(
                    $context,
                    $resultSlot,
                    $i64->constInt($payload, true)
                );
            }
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
}
