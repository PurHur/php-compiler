<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamGlobalsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for boxed gettype() from ext/standard (#3618, #5235, #30792, #33398). */
final class JitGettype
{
    public static function boxed(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::normalizeValuePtr(
            $context,
            JitValueBox::valuePtrFromVariable($context, $arg)
        );
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        // Mask IS_REFCOUNTED — AOT stores JIT tags (TYPE_*|0x80) (#26854 / #26885 / #33398).
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $strPtr = $context->getTypeFromString('__string__*');

        $result = $context->builder->load($context->constantStringFromString('unknown'));
        foreach ([
            JITVariable::TYPE_NULL => 'NULL',
            JITVariable::TYPE_NATIVE_BOOL => 'boolean',
            JITVariable::TYPE_NATIVE_DOUBLE => 'double',
            JITVariable::TYPE_STRING => 'string',
            JITVariable::TYPE_OBJECT => 'object',
            VmVariable::TYPE_ENUM_CASE => 'object',
        ] as $jitType => $name) {
            $expected = $i8->constInt($jitType & 0x7f, false);
            $isType = $context->builder->icmp(Builder::INT_EQ, $kind, $expected);
            $candidate = $context->builder->load($context->constantStringFromString($name));
            $result = $context->builder->select($isType, $candidate, $result);
        }

        // BB-guard HT probe — eager __value__readHashtable on a float box SIGSEGVs (#33398).
        // Peer: get_debug_type jitGetDebugTypeBoxed (#26885).
        $isHt = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_HASHTABLE & 0x7f, false)
        );
        $scalarBb = $context->builder->getInsertBlock();
        $htProbeBb = BasicBlockHelper::append($context, 'gettype_ht_probe');
        $afterHtBb = BasicBlockHelper::append($context, 'gettype_after_ht');
        $context->builder->branchIf($isHt, $htProbeBb, $afterHtBb);

        $context->builder->positionAtEnd($htProbeBb);
        $htLabel = $context->builder->select(
            JitStreamContextRepresentation::isRepresentation(
                $context,
                $context->builder->call(
                    $context->lookupFunction('__value__readHashtable'),
                    $valuePtr
                )
            ),
            $context->builder->load($context->constantStringFromString('resource')),
            $context->builder->load($context->constantStringFromString('array'))
        );
        $htEnd = $context->builder->getInsertBlock();
        $context->builder->branch($afterHtBb);

        $context->builder->positionAtEnd($afterHtBb);
        $afterHtPhi = $context->builder->phi($strPtr, 'gettype_ht_phi');
        $afterHtPhi->addIncoming($result, $scalarBb);
        $afterHtPhi->addIncoming($htLabel, $htEnd);
        $result = $afterHtPhi;

        // BB-guard long/resource probe — eager readLong + labelForHandle GEP on float
        // bits (e.g. 9.22e18) indexes StreamGlobals OOB (#33398).
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_NATIVE_LONG & 0x7f, false)
        );
        $afterHtEnd = $context->builder->getInsertBlock();
        $longProbeBb = BasicBlockHelper::append($context, 'gettype_long_probe');
        $doneBb = BasicBlockHelper::append($context, 'gettype_boxed_done');
        $context->builder->branchIf($isLong, $longProbeBb, $doneBb);

        $context->builder->positionAtEnd($longProbeBb);
        $handle = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        $longLabel = self::labelForHandle($context, $handle);
        $longEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($strPtr, 'gettype_boxed_phi');
        $phi->addIncoming($result, $afterHtEnd);
        $phi->addIncoming($longLabel, $longEnd);

        return $phi;
    }

    /**
     * gettype() for stream handles — open / closed / plain int (#30792).
     *
     * php-src: closed resources keep the zval but is_resource is false; gettype says
     * "resource (closed)" when {@see StreamGlobalsJit::GLOBAL_WAS_USED} is set.
     */
    public static function labelForHandle(Context $context, Value $handle): Value
    {
        $isResource = JitIsResource::invoke($context, $handle);
        $closed = self::isClosedStreamHandle($context, $handle);

        return $context->builder->select(
            $isResource,
            $context->builder->load($context->constantStringFromString('resource')),
            $context->builder->select(
                $closed,
                $context->builder->load($context->constantStringFromString('resource (closed)')),
                $context->builder->load($context->constantStringFromString('integer'))
            )
        );
    }

    /**
     * True when handle was opened (was_used) but {@see __compiler_is_resource} is now false.
     *
     * Must branch on range **before** GEP/load — AND-after-load still indexes
     * `phpc_stream_was_used[handle]` for ints ≥ {@see StreamGlobalsJit::MAX_HANDLES}
     * and negatives, which SIGSEGVs thin AOT `var_dump`/`print_r` (#34519 / re-#34507).
     * Peer: {@see StreamGlobalsJit} resolve_table BB-guard / #33398.
     */
    public static function isClosedStreamHandle(Context $context, Value $handle): Value
    {
        // Capture caller insert before ensureGlobals — append on *this* function only
        // (BasicBlockHelper::append follows activeFunction and can land in another fn; #34507/#34519).
        $insert = $context->builder->getInsertBlock();
        StreamGlobalsJit::ensureGlobals($context);
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $zero = $i64->constInt(0, false);
        $three = $i64->constInt(3, false);
        $max = $i64->constInt(StreamGlobalsJit::MAX_HANDLES, false);
        $false = $context->constantFromBool(false);

        $global = $context->module->getNamedGlobal(StreamGlobalsJit::GLOBAL_WAS_USED);
        if (null === $global) {
            return $false;
        }

        $context->builder->positionAtEnd($insert);
        $fn = $insert->getParent();
        if (null === $fn) {
            throw new \LogicException('isClosedStreamHandle: insert block has no parent function');
        }

        $inRange = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $handle, $three),
            $context->builder->icmp(Builder::INT_SLT, $handle, $max)
        );
        $probeBb = $fn->appendBasicBlock('closed_stream_was_used_probe');
        $oobBb = $fn->appendBasicBlock('closed_stream_was_used_oob');
        $doneBb = $fn->appendBasicBlock('closed_stream_was_used_done');
        $context->builder->branchIf($inRange, $probeBb, $oobBb);

        $context->builder->positionAtEnd($oobBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($probeBb);
        $slot = $context->builder->gep($global, $zero, $handle);
        $wasUsed = $context->builder->load($slot);
        $used = $context->builder->icmp(Builder::INT_NE, $wasUsed, $i8->constInt(0, false));
        $probeEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($i1, 'closed_stream_was_used_phi');
        $phi->addIncoming($false, $oobBb);
        $phi->addIncoming($used, $probeEnd);

        return $phi;
    }
}
