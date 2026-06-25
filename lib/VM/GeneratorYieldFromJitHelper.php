<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\IteratorProtocolHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT yield-from LLVM lowering — PHP SSOT (#10105, php-in-PHP).
 *
 * php-src: Zend/zend_generators.c — ZEND_GENERATOR_RETURN, yield from
 */
final class GeneratorYieldFromJitHelper
{
    public static function emitYieldFromPoint(
        \PHPCompiler\JIT $jit,
        Block $block,
        OpCode $op,
        Value $stateParam,
        int $resumeIp
    ): void {
        $context = $jit->context;
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $invalidIdx = $context->builder->sub($zero, $sizeT->constInt(1, false));
        $fn = $context->builder->getInsertBlock()->getParent();
        $innerResumeName = GeneratorJitHelper::resolveYieldFromGeneratorResumeName($block, $op, $context);
        $containerUserType = GeneratorJitHelper::yieldFromContainerUserType($block, $op);
        $supportsIterator = false;
        if (null !== $op->arg2) {
            try {
                $previewVar = $context->getVariableFromOp($block->getOperand($op->arg2));
                $supportsIterator = IteratorProtocolHelper::canLowerIteratorProtocol(
                    $context,
                    $previewVar,
                    $containerUserType
                );
            } catch (\LogicException) {
                $supportsIterator = false;
            }
        }

        $activeField = $context->builder->structGep($stateParam, $map['yield_from_active']);
        $htField = $context->builder->structGep($stateParam, $map['yield_from_ht']);
        $idxField = $context->builder->structGep($stateParam, $map['yield_from_idx']);
        $isGenField = $context->builder->structGep($stateParam, $map['yield_from_is_generator']);
        $isIterField = $context->builder->structGep($stateParam, $map['yield_from_is_iterator']);
        $active = $context->builder->load($activeField);

        $initBb = $fn->appendBasicBlock('gen_yf_init');
        $dispatchBb = $fn->appendBasicBlock('gen_yf_dispatch');
        $arrayIterBb = $fn->appendBasicBlock('gen_yf_iter_array');
        $genIterBb = $fn->appendBasicBlock('gen_yf_iter_gen');
        $iterIterBb = $supportsIterator ? $fn->appendBasicBlock('gen_yf_iter_obj') : null;
        $context->builder->branchIf($active, $dispatchBb, $initBb);

        $context->builder->positionAtEnd($initBb);
        $points = GeneratorJitHelper::collectResumePoints($block);
        $prefixStart = GeneratorJitHelper::resumePrefixStart($points, $resumeIp);
        $containerVar = $jit->compileGeneratorYieldFromSetup(
            $fn,
            $block,
            $initBb,
            $op,
            $innerResumeName,
            $prefixStart
        );
        $effectiveResumeName = $innerResumeName ?? $containerVar->generatorResumeName;
        $i64 = $context->getTypeFromString('int64');
        $invalidContainerBb = $fn->appendBasicBlock('gen_yf_invalid_container');
        if (
            null !== $effectiveResumeName
            && GeneratorJitHelper::isGeneratorVariable($containerVar)
            && null !== $containerVar->generatorStatePtr
        ) {
            $innerState = $containerVar->generatorStatePtr;
            $context->builder->store(
                self::castGeneratorStateToHtPtr($context, $innerState),
                $htField
            );
            $context->builder->store($i1->constInt(1, false), $isGenField);
            $context->builder->store($i1->constInt(0, false), $isIterField);
            $context->builder->store($i1->constInt(1, false), $activeField);
            VmGenerator::resetStateInPlace($context, $innerState);
            $context->builder->branch($genIterBb);
        } elseif (Variable::TYPE_STRING === $containerVar->type) {
            $context->builder->branch($invalidContainerBb);
        } elseif (
            ($containerVar->type & Variable::IS_NATIVE_ARRAY)
            || Variable::TYPE_HASHTABLE === $containerVar->type
        ) {
            if ($containerVar->type & Variable::IS_NATIVE_ARRAY) {
                $htPtr = HashTableHelper::materializeNativeArrayForCall($context, $containerVar);
            } else {
                $htPtr = $context->helper->loadValue(HashTableHelper::asDetachedHashtable($context, $containerVar));
            }
            $context->builder->store($htPtr, $htField);
            $context->builder->store($invalidIdx, $idxField);
            $context->builder->store($i1->constInt(0, false), $isGenField);
            $context->builder->store($i1->constInt(0, false), $isIterField);
            $context->builder->store($i1->constInt(1, false), $activeField);
            $context->builder->branch($arrayIterBb);
        } elseif (
            Variable::TYPE_VALUE === $containerVar->type
            && !IteratorProtocolHelper::canLowerIteratorProtocol($context, $containerVar, $containerUserType)
        ) {
            self::compileYieldFromValueBoxContainerInit(
                $context,
                $fn,
                $containerVar,
                $stateParam,
                $map,
                $htField,
                $idxField,
                $activeField,
                $isGenField,
                $isIterField,
                $invalidIdx,
                $arrayIterBb,
                $invalidContainerBb
            );
        } elseif (IteratorProtocolHelper::canLowerIteratorProtocol($context, $containerVar, $containerUserType)) {
            $receiver = IteratorProtocolHelper::resolveForeachReceiver(
                $context,
                $containerVar,
                $containerUserType
            );
            $obj = Variable::KIND_VALUE === $receiver->kind
                ? $receiver->value
                : $context->builder->load($receiver->value);
            $context->refcount->addref($obj);
            $context->builder->store($obj, $context->builder->structGep($stateParam, $map['yield_from_iter_obj']));
            IteratorProtocolHelper::invokeIteratorMethod($context, $receiver, 'rewind', $containerUserType);
            $context->builder->store($i1->constInt(0, false), $isGenField);
            $context->builder->store($i1->constInt(1, false), $isIterField);
            $context->builder->store($i1->constInt(0, false), $context->builder->structGep($stateParam, $map['yield_from_iter_advance']));
            $context->builder->store($i1->constInt(1, false), $activeField);
            $context->builder->branch($iterIterBb);
        } else {
            throw new \LogicException('yield from in JIT requires array, Generator, or Iterator container (issue #4562)');
        }

        $context->builder->positionAtEnd($invalidContainerBb);
        self::emitYieldFromStringErrorAndFinish($context, $stateParam, $map, $i1, $i64);

        $context->builder->positionAtEnd($dispatchBb);
        $isGen = $context->builder->load($isGenField);
        if ($supportsIterator) {
            $isIter = $context->builder->load($isIterField);
            $notGenBb = $fn->appendBasicBlock('gen_yf_not_gen');
            $context->builder->branchIf($isGen, $genIterBb, $notGenBb);
            $context->builder->positionAtEnd($notGenBb);
            $context->builder->branchIf($isIter, $iterIterBb, $arrayIterBb);
        } else {
            $context->builder->branchIf($isGen, $genIterBb, $arrayIterBb);
        }

        $context->builder->positionAtEnd($genIterBb);
        if (null !== $effectiveResumeName) {
            self::emitYieldFromGeneratorIter(
                $context,
                $stateParam,
                $htField,
                $activeField,
                $isGenField,
                $isIterField,
                $effectiveResumeName,
                $resumeIp,
                $fn
            );
        } else {
            $context->builder->returnValue($context->getTypeFromString('int64')->constInt(0, false));
        }

        $context->builder->positionAtEnd($arrayIterBb);
        self::emitYieldFromArrayIter(
            $context,
            $stateParam,
            $htField,
            $idxField,
            $activeField,
            $isGenField,
            $isIterField,
            $resumeIp,
            $fn,
            $arrayIterBb
        );

        if ($supportsIterator && null !== $iterIterBb) {
            $context->builder->positionAtEnd($iterIterBb);
            self::emitYieldFromIteratorIter(
                $context,
                $stateParam,
                $activeField,
                $isGenField,
                $isIterField,
                $containerUserType,
                $resumeIp,
                $fn
            );
        }
    }

    private static function castGeneratorStateToHtPtr(Context $context, Value $statePtr): Value
    {
        return $context->builder->pointerCast(
            $statePtr,
            $context->getTypeFromString('__hashtable__*')
        );
    }

    private static function loadYieldFromGeneratorState(Context $context, Value $htField): Value
    {
        $loaded = $context->builder->load($htField);

        return $context->builder->pointerCast(
            $loaded,
            $context->getTypeFromString('__generator_state__*')
        );
    }

    private static function copyVariableToStateValueField(
        Context $context,
        Variable $src,
        Value $destField
    ): void {
        JitValueBox::copyFromPointer(
            $context,
            $destField,
            JitValueBox::valuePtrFromVariable($context, $src)
        );
    }

    private static function emitYieldFromGeneratorIter(
        Context $context,
        Value $stateParam,
        Value $htField,
        Value $activeField,
        Value $isGenField,
        Value $isIterField,
        string $innerResumeName,
        int $resumeIp,
        \PHPLLVM\Value\Function_ $fn
    ): void {
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $innerState = self::loadYieldFromGeneratorState($context, $htField);
        $resumeFn = $context->functions[strtolower($innerResumeName)] ?? null;
        if (!$resumeFn instanceof \PHPLLVM\Value\Function_) {
            throw new \LogicException('Generator resume function missing for yield from: '.$innerResumeName);
        }
        $yielded = $context->builder->call($resumeFn, $innerState);
        $hasYield = $context->builder->icmp(
            Builder::INT_NE,
            $yielded,
            $i64->constInt(0, false)
        );
        $yieldBb = $fn->appendBasicBlock('gen_yf_gen_yield');
        $exhausted = $fn->appendBasicBlock('gen_yf_gen_exhausted');
        $context->builder->branchIf($hasYield, $yieldBb, $exhausted);

        $context->builder->positionAtEnd($yieldBb);
        VmGenerator::copyCurrentFromInnerToOuter($context, $stateParam, $innerState);
        $context->builder->store($i1->constInt(1, false), $context->builder->structGep($stateParam, $map['has_current']));
        $context->builder->store($sizeT->constInt($resumeIp, false), $context->builder->structGep($stateParam, $map['resume_ip']));
        $context->builder->returnValue($i64->constInt(1, false));

        $context->builder->positionAtEnd($exhausted);
        $context->builder->store($i1->constInt(0, false), $activeField);
        $context->builder->store($i1->constInt(0, false), $isGenField);
        $context->builder->store($i1->constInt(0, false), $isIterField);
        $context->builder->store(
            $sizeT->constInt($resumeIp + 1, false),
            $context->builder->structGep($stateParam, $map['resume_ip'])
        );
        $context->builder->returnValue($i64->constInt(0, false));
    }

    private static function emitYieldFromIteratorIter(
        Context $context,
        Value $stateParam,
        Value $activeField,
        Value $isGenField,
        Value $isIterField,
        ?string $containerUserType,
        int $resumeIp,
        \PHPLLVM\Value\Function_ $fn
    ): void {
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $objField = $context->builder->structGep($stateParam, $map['yield_from_iter_obj']);
        $advanceField = $context->builder->structGep($stateParam, $map['yield_from_iter_advance']);
        $obj = $context->builder->load($objField);
        $receiver = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);

        $maybeNext = $fn->appendBasicBlock('gen_yf_iter_maybe_next');
        $checkValid = $fn->appendBasicBlock('gen_yf_iter_valid');
        $yieldBb = $fn->appendBasicBlock('gen_yf_iter_yield');
        $exhausted = $fn->appendBasicBlock('gen_yf_iter_exhausted');

        $needsNext = $context->builder->load($advanceField);
        $context->builder->branchIf($needsNext, $maybeNext, $checkValid);

        $context->builder->positionAtEnd($maybeNext);
        IteratorProtocolHelper::invokeIteratorMethod($context, $receiver, 'next', $containerUserType);
        $context->builder->store($i1->constInt(0, false), $advanceField);
        $context->builder->branch($checkValid);

        $context->builder->positionAtEnd($checkValid);
        $valid = IteratorProtocolHelper::invokeIteratorMethodBool($context, $receiver, 'valid', $containerUserType);
        $context->builder->branchIf($valid, $yieldBb, $exhausted);

        $context->builder->positionAtEnd($yieldBb);
        $key = IteratorProtocolHelper::invokeIteratorMethodValue($context, $receiver, 'key', $containerUserType);
        $value = IteratorProtocolHelper::invokeIteratorMethodValue($context, $receiver, 'current', $containerUserType);
        self::copyVariableToStateValueField(
            $context,
            $key,
            $context->builder->structGep($stateParam, $map['current_key'])
        );
        self::copyVariableToStateValueField(
            $context,
            $value,
            $context->builder->structGep($stateParam, $map['current_value'])
        );
        $context->builder->store($i1->constInt(1, false), $advanceField);
        $context->builder->store($i1->constInt(1, false), $context->builder->structGep($stateParam, $map['has_current']));
        $context->builder->store($sizeT->constInt($resumeIp, false), $context->builder->structGep($stateParam, $map['resume_ip']));
        $context->builder->returnValue($i64->constInt(1, false));

        $context->builder->positionAtEnd($exhausted);
        $context->builder->store($i1->constInt(0, false), $activeField);
        $context->builder->store($i1->constInt(0, false), $isGenField);
        $context->builder->store($i1->constInt(0, false), $isIterField);
        $context->builder->store(
            $sizeT->constInt($resumeIp + 1, false),
            $context->builder->structGep($stateParam, $map['resume_ip'])
        );
        $context->builder->returnValue($i64->constInt(0, false));
    }

    private static function emitYieldFromArrayIter(
        Context $context,
        Value $stateParam,
        Value $htField,
        Value $idxField,
        Value $activeField,
        Value $isGenField,
        Value $isIterField,
        int $resumeIp,
        \PHPLLVM\Value\Function_ $fn,
        \PHPLLVM\BasicBlock $iterBb
    ): void {
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $htMap = $context->structFieldMap['__hashtable__'];
        $one = $sizeT->constInt(1, false);

        $context->builder->positionAtEnd($iterBb);
        $idx = $context->builder->load($idxField);
        $nextIdx = $context->builder->addNoSignedWrap($idx, $one);
        $context->builder->store($nextIdx, $idxField);
        $ht = $context->builder->load($htField);
        $nextFree = $context->builder->load($context->builder->structGep($ht, $htMap['nextFreeElement']));
        $inPacked = $context->builder->icmp(Builder::INT_ULT, $nextIdx, $nextFree);

        $packedBody = $fn->appendBasicBlock('gen_yf_packed');
        $exhausted = $fn->appendBasicBlock('gen_yf_exhausted');
        $yieldBb = $fn->appendBasicBlock('gen_yf_yield');
        $advancePacked = $fn->appendBasicBlock('gen_yf_advance');
        $context->builder->branchIf($inPacked, $packedBody, $exhausted);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $nextIdx
        );
        $context->builder->branchIf($isSet, $yieldBb, $advancePacked);

        $context->builder->positionAtEnd($advancePacked);
        $context->builder->branch($iterBb);

        $context->builder->positionAtEnd($yieldBb);
        $keyField = $context->builder->structGep($stateParam, $map['current_key']);
        $valField = $context->builder->structGep($stateParam, $map['current_value']);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $keyField),
            $context->builder->truncOrBitCast($nextIdx, $context->getTypeFromString('int64'))
        );
        $values = $context->builder->load($context->builder->structGep($ht, $htMap['values']));
        $entry = $context->builder->inBoundsGep($values, $nextIdx);
        JitValueBox::copyFromPointer($context, $valField, $entry);
        $context->builder->store($i1->constInt(1, false), $context->builder->structGep($stateParam, $map['has_current']));
        $context->builder->store($sizeT->constInt($resumeIp, false), $context->builder->structGep($stateParam, $map['resume_ip']));
        $context->builder->returnValue($i64->constInt(1, false));

        $context->builder->positionAtEnd($exhausted);
        $context->builder->store($i1->constInt(0, false), $activeField);
        $context->builder->store($i1->constInt(0, false), $isGenField);
        $context->builder->store($i1->constInt(0, false), $isIterField);
        $context->builder->store(
            $sizeT->constInt($resumeIp + 1, false),
            $context->builder->structGep($stateParam, $map['resume_ip'])
        );
        $context->builder->returnValue($i64->constInt(0, false));
    }

    private static function emitYieldFromStringErrorAndFinish(
        Context $context,
        Value $stateParam,
        array $map,
        \PHPLLVM\Type\IntegerType $i1,
        \PHPLLVM\Type\IntegerType $i64
    ): void {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, GeneratorJitHelper::YIELD_FROM_STRING_ERROR);
        $context->builder->store($i1->constInt(1, false), $context->builder->structGep($stateParam, $map['done']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($stateParam, $map['has_current']));
        $context->builder->returnValue($i64->constInt(0, false));
    }

    private static function compileYieldFromValueBoxContainerInit(
        Context $context,
        \PHPLLVM\Value\Function_ $fn,
        Variable $containerVar,
        Value $stateParam,
        array $map,
        Value $htField,
        Value $idxField,
        Value $activeField,
        Value $isGenField,
        Value $isIterField,
        Value $invalidIdx,
        \PHPLLVM\BasicBlock $arrayIterBb,
        \PHPLLVM\BasicBlock $invalidContainerBb
    ): void {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $containerVar);
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $context->structFieldMap['__value__']['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $tag = 'u'.(string) spl_object_id($context);

        $arrayBb = $fn->appendBasicBlock('gen_yf_vb_array_'.$tag);
        $stringBb = $fn->appendBasicBlock('gen_yf_vb_string_'.$tag);
        $failBb = $fn->appendBasicBlock('gen_yf_vb_fail_'.$tag);
        $afterArray = $fn->appendBasicBlock('gen_yf_vb_after_array_'.$tag);

        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_ARRAY, false)
        );
        $context->builder->branchIf($isArray, $arrayBb, $afterArray);

        $context->builder->positionAtEnd($afterArray);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_STRING, false)
        );
        $context->builder->branchIf($isString, $stringBb, $failBb);

        $context->builder->positionAtEnd($arrayBb);
        $htPtr = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valuePtr
        );
        $context->builder->store($htPtr, $htField);
        $context->builder->store($invalidIdx, $idxField);
        $context->builder->store($i1->constInt(0, false), $isGenField);
        $context->builder->store($i1->constInt(0, false), $isIterField);
        $context->builder->store($i1->constInt(1, false), $activeField);
        $context->builder->branch($arrayIterBb);

        $context->builder->positionAtEnd($stringBb);
        $context->builder->branch($invalidContainerBb);

        $context->builder->positionAtEnd($failBb);
        self::emitYieldFromStringErrorAndFinish($context, $stateParam, $map, $i1, $context->getTypeFromString('int64'));
    }
}
