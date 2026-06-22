<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\ArrayMapCallbackPolicy;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\GeneratorHelper;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\IteratorHelper;
use PHPCompiler\JIT\IteratorProtocolHelper;
use PHPCompiler\JIT\JitIterableArg;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT lowering for iterator_count() and iterator_apply() (#3313, php-src ext/spl/iterator.c).
 */
final class JitIteratorWalk
{
    /** Zend ext/spl/php_spl.c — iterator_count() on exhausted Generator (#5132). */
    private const CLOSED_GENERATOR_ITERATOR_COUNT_ERROR = 'Cannot traverse an already closed generator';

    public static function count(Context $context, Variable $iterable): Value
    {
        if (!JitIterableArg::guardIterableOperand($context, $iterable, 'iterator_count')) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        GeneratorHelper::ensureTypes($context);
        $gen = self::resolveGenerator($iterable);
        if (null !== $gen) {
            return self::countGenerator($context, $gen);
        }
        if ($iterable->type & Variable::IS_NATIVE_ARRAY) {
            return self::countNativeArray($context, $iterable);
        }
        if (Variable::TYPE_HASHTABLE === $iterable->type) {
            return self::countHashTable($context, $iterable);
        }
        if (Variable::TYPE_OBJECT === $iterable->type || Variable::TYPE_VALUE === $iterable->type) {
            if (IteratorProtocolHelper::canLowerIteratorProtocol($context, $iterable, null)) {
                return self::countIteratorObject($context, $iterable);
            }
        }

        JitIterableArg::emitIterableTypeErrorAndAbort(
            $context,
            'iterator_count',
            0,
            'iterator',
            JitOperandTypeLabel::givenLabel($context, $iterable)
        );

        return $context->getTypeFromString('int64')->constInt(0, false);
    }

    public static function apply(Context $context, Variable $iterable, Variable $callback, Variable $params): Value
    {
        if (!JitIterableArg::guardIterableOperand($context, $iterable, 'iterator_apply')) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (!ArrayMapCallbackPolicy::isClosureJitLowerable($callback)) {
            throw new \LogicException(
                'iterator_apply() requires a compile-time closure callback in this compiler build'
            );
        }
        $closureCall = $callback->closureCall;
        if (null === $closureCall) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }
        GeneratorHelper::ensureTypes($context);
        $gen = self::resolveGenerator($iterable);
        if (null !== $gen) {
            return self::applyGenerator($context, $gen, $closureCall);
        }
        if ($iterable->type & Variable::IS_NATIVE_ARRAY) {
            return self::applyNativeArray($context, $iterable, $closureCall);
        }
        if (Variable::TYPE_HASHTABLE === $iterable->type) {
            return self::applyHashTable($context, $iterable, $closureCall);
        }
        if (Variable::TYPE_OBJECT === $iterable->type || Variable::TYPE_VALUE === $iterable->type) {
            if (IteratorProtocolHelper::canLowerIteratorProtocol($context, $iterable, null)) {
                return self::applyIteratorObject($context, $iterable, $closureCall);
            }
        }

        JitIterableArg::emitIterableTypeErrorAndAbort(
            $context,
            'iterator_apply',
            0,
            'iterator',
            JitOperandTypeLabel::givenLabel($context, $iterable)
        );

        return $context->getTypeFromString('int64')->constInt(0, false);
    }

    private static function resolveGenerator(Variable $iterable): ?Variable
    {
        if (GeneratorHelper::isGeneratorVariable($iterable)) {
            return $iterable;
        }

        return null;
    }

    private static function countNativeArray(Context $context, Variable $array): Value
    {
        $ht = HashTableHelper::materializeNativeArrayForCall($context, $array);

        return self::countHashTable($context, new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $ht
        ));
    }

    private static function countHashTable(Context $context, Variable $array): Value
    {
        $ht = $context->helper->loadValue($array);
        $map = $context->structFieldMap['__hashtable__'];
        $num = $context->builder->load($context->builder->structGep($ht, $map['numElements']));

        return $context->builder->truncOrBitCast($num, $context->getTypeFromString('int64'));
    }

    private static function countGenerator(Context $context, Variable $gen): Value
    {
        $statePtr = GeneratorHelper::loadStateFromGeneratorObject($context, $gen);
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $resumeIp = $context->builder->load($context->builder->structGep($statePtr, $map['resume_ip']));
        $hasCurrent = $context->builder->load($context->builder->structGep($statePtr, $map['has_current']));
        $started = $context->builder->or(
            $context->builder->icmp(Builder::INT_NE, $resumeIp, $zero),
            $context->builder->icmp(Builder::INT_NE, $hasCurrent, $i1->constInt(0, false))
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $okBlock = $fn->appendBasicBlock('iterator_count_gen_ok');
        $failBlock = $fn->appendBasicBlock('iterator_count_gen_fail');
        $context->builder->branchIf($started, $failBlock, $okBlock);
        $context->builder->positionAtEnd($failBlock);
        TryCatchHelper::emitCatchableClassError(
            $context,
            'Exception',
            self::CLOSED_GENERATOR_ITERATOR_COUNT_ERROR
        );
        $context->builder->positionAtEnd($okBlock);
        GeneratorHelper::compileIterReset($context, $gen);
        $countSlot = $context->builder->alloca($context->getTypeFromString('int64'), 1, 'iterator_count_n');
        $context->builder->store(
            $context->getTypeFromString('int64')->constInt(0, false),
            $countSlot
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $head = $fn->appendBasicBlock('iterator_count_head');
        $body = $fn->appendBasicBlock('iterator_count_body');
        $done = $fn->appendBasicBlock('iterator_count_done');
        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $valid = GeneratorHelper::compileIterValid($context, $gen);
        $context->builder->branchIf($valid, $body, $done);
        $context->builder->positionAtEnd($body);
        $cur = $context->builder->load($countSlot);
        $context->builder->store(
            $context->builder->add($cur, $context->getTypeFromString('int64')->constInt(1, false)),
            $countSlot
        );
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);

        return $context->builder->load($countSlot);
    }

    private static function countIteratorObject(Context $context, Variable $iterable): Value
    {
        $receiver = IteratorProtocolHelper::normalizeObjectReceiver($context, $iterable);
        IteratorProtocolHelper::invokeIteratorMethod($context, $receiver, 'rewind');
        $countSlot = $context->builder->alloca($context->getTypeFromString('int64'), 1, 'iterator_count_obj_n');
        $context->builder->store(
            $context->getTypeFromString('int64')->constInt(0, false),
            $countSlot
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $head = $fn->appendBasicBlock('iterator_count_obj_head');
        $body = $fn->appendBasicBlock('iterator_count_obj_body');
        $done = $fn->appendBasicBlock('iterator_count_obj_done');
        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $valid = IteratorProtocolHelper::invokeIteratorMethodBool($context, $receiver, 'valid');
        $context->builder->branchIf($valid, $body, $done);
        $context->builder->positionAtEnd($body);
        $cur = $context->builder->load($countSlot);
        $context->builder->store(
            $context->builder->add($cur, $context->getTypeFromString('int64')->constInt(1, false)),
            $countSlot
        );
        IteratorProtocolHelper::invokeIteratorMethod($context, $receiver, 'next');
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);

        return $context->builder->load($countSlot);
    }

    private static function applyNativeArray(Context $context, Variable $array, Call $closureCall): Value
    {
        $ht = HashTableHelper::materializeNativeArrayForCall($context, $array);

        return self::applyHashTable($context, new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $ht
        ), $closureCall);
    }

    private static function applyHashTable(Context $context, Variable $array, Call $closureCall): Value
    {
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $countSlot = $context->builder->alloca($context->getTypeFromString('int64'), 1, 'iterator_apply_n');
        $context->builder->store(
            $context->getTypeFromString('int64')->constInt(0, false),
            $countSlot
        );
        IteratorHelper::compileReset($context, $array, null);
        $head = BasicBlockHelper::append($context, 'iterator_apply_head');
        $work = BasicBlockHelper::append($context, 'iterator_apply_work');
        $advance = BasicBlockHelper::append($context, 'iterator_apply_advance');
        $done = BasicBlockHelper::append($context, 'iterator_apply_done');
        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $valid = IteratorHelper::compileValid($context, $array, null);
        $context->builder->branchIf($valid, $work, $done);
        $context->builder->positionAtEnd($work);
        $key = IteratorHelper::compileKey($context, $array, null);
        $value = IteratorHelper::compileValue($context, $array, null);
        $result = $closureCall->call($context, $value, $key);
        $keep = IteratorProtocolHelper::truthyI1($context, $result);
        $context->builder->branchIf($keep, $advance, $done);
        $context->builder->positionAtEnd($advance);
        $cur = $context->builder->load($countSlot);
        $context->builder->store(
            $context->builder->add($cur, $context->getTypeFromString('int64')->constInt(1, false)),
            $countSlot
        );
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);

        return $context->builder->load($countSlot);
    }

    private static function applyGenerator(Context $context, Variable $gen, Call $closureCall): Value
    {
        GeneratorHelper::compileIterReset($context, $gen);
        $countSlot = $context->builder->alloca($context->getTypeFromString('int64'), 1, 'iterator_apply_gen_n');
        $context->builder->store(
            $context->getTypeFromString('int64')->constInt(0, false),
            $countSlot
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $head = $fn->appendBasicBlock('iterator_apply_gen_head');
        $body = $fn->appendBasicBlock('iterator_apply_gen_body');
        $done = $fn->appendBasicBlock('iterator_apply_gen_done');
        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $valid = GeneratorHelper::compileIterValid($context, $gen);
        $context->builder->branchIf($valid, $body, $done);
        $context->builder->positionAtEnd($body);
        $value = GeneratorHelper::compileIterValue($context, $gen);
        $key = GeneratorHelper::compileIterKey($context, $gen);
        $result = $closureCall->call($context, $value, $key);
        $keep = IteratorProtocolHelper::truthyI1($context, $result);
        $advance = $fn->appendBasicBlock('iterator_apply_gen_advance');
        $context->builder->branchIf($keep, $advance, $done);
        $context->builder->positionAtEnd($advance);
        $cur = $context->builder->load($countSlot);
        $context->builder->store(
            $context->builder->add($cur, $context->getTypeFromString('int64')->constInt(1, false)),
            $countSlot
        );
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);

        return $context->builder->load($countSlot);
    }

    private static function applyIteratorObject(Context $context, Variable $iterable, Call $closureCall): Value
    {
        $receiver = IteratorProtocolHelper::normalizeObjectReceiver($context, $iterable);
        IteratorProtocolHelper::invokeIteratorMethod($context, $receiver, 'rewind');
        $countSlot = $context->builder->alloca($context->getTypeFromString('int64'), 1, 'iterator_apply_obj_n');
        $context->builder->store(
            $context->getTypeFromString('int64')->constInt(0, false),
            $countSlot
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $head = $fn->appendBasicBlock('iterator_apply_obj_head');
        $body = $fn->appendBasicBlock('iterator_apply_obj_body');
        $done = $fn->appendBasicBlock('iterator_apply_obj_done');
        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $valid = IteratorProtocolHelper::invokeIteratorMethodBool($context, $receiver, 'valid');
        $context->builder->branchIf($valid, $body, $done);
        $context->builder->positionAtEnd($body);
        $value = IteratorProtocolHelper::invokeIteratorMethodValue($context, $receiver, 'current');
        $key = IteratorProtocolHelper::invokeIteratorMethodValue($context, $receiver, 'key');
        $result = $closureCall->call($context, $value, $key);
        $keep = IteratorProtocolHelper::truthyI1($context, $result);
        $advance = $fn->appendBasicBlock('iterator_apply_obj_advance');
        $context->builder->branchIf($keep, $advance, $done);
        $context->builder->positionAtEnd($advance);
        $cur = $context->builder->load($countSlot);
        $context->builder->store(
            $context->builder->add($cur, $context->getTypeFromString('int64')->constInt(1, false)),
            $countSlot
        );
        IteratorProtocolHelper::invokeIteratorMethod($context, $receiver, 'next');
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);

        return $context->builder->load($countSlot);
    }
}
