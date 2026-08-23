<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT ReflectionClass::getFileName() (#34096).
 *
 * Name (from ReflectionClass) → declaring filename string, or bool false when
 * internal / unknown / empty (php-src zim_ReflectionClass_getFileName /
 * {@see \PHPCompiler\VM\ReflectionSupport::returnFileName}).
 *
 * Must not use {@see Type\Object_::classIdFromRuntimeName} — that aborts on
 * names absent from the JIT class table (e.g. stdClass). Peer: name memcmp
 * tables in {@see ReflectionClassKindNameTableRuntime}.
 *
 * Filenames come from DECLARE_* {@see OpCode::$sourceLocation} recorded on
 * {@see Type\Object_} — MODE_AOT does not populate VM ClassEntry::sourceLocation.
 */
final class ReflectionClassGetFileNameRuntime
{
    private const MAX_NAME_LEN = 512;

    /**
     * @return Value __value__* result slot (string|false)
     */
    public static function emit(Context $context, Value $nameCstr, Value $nameLen): Value
    {
        LibcExtern::ensureMemcmpDecl($context);

        $object = $context->type->object;
        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $merge = $fn->appendBasicBlock('refl_getfilename_merge');
        $miss = $fn->appendBasicBlock('refl_getfilename_miss');
        $fold = $fn->appendBasicBlock('refl_getfilename_fold');

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
        $loop = $fn->appendBasicBlock('refl_getfilename_fold_loop');
        $afterFold = $fn->appendBasicBlock('refl_getfilename_after_fold');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxAlloca);
        $foldDone = $context->builder->icmp(Builder::INT_EQ, $idx, $nameLen);
        $body = $fn->appendBasicBlock('refl_getfilename_fold_body');
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
            $file = $loc->forReflection()->filename;
            if ('' === $file || 'unknown' === $file) {
                continue;
            }
            $lcName = strtolower(ltrim((string) $className, '\\'));
            $wantLenInt = \strlen($lcName);
            if (0 === $wantLenInt) {
                continue;
            }

            $matchBlock = $fn->appendBasicBlock('refl_getfilename_hit_'.$hitIdx);
            $nextCheck = $fn->appendBasicBlock('refl_getfilename_try_'.$hitIdx);
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
                $i64->constInt(\strlen($file), false),
                $context->builder->pointerCast(
                    $context->constantFromString($file),
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
}
