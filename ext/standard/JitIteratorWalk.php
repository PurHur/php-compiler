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
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT lowering for iterator_count() and iterator_apply() (#3313, php-src ext/spl/iterator.c).
 */
final class JitIteratorWalk
{
    public static function count(Context $context, Variable $iterable): Value
    {
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
            throw new \LogicException(
                'iterator_count() on object Traversable is not supported by AOT in this compiler build'
            );
        }

        throw new \LogicException(
            'iterator_count() argument must be an array or Traversable in this compiler build'
        );
    }

    public static function apply(Context $context, Variable $iterable, Variable $callback, Variable $params): Value
    {
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
            throw new \LogicException(
                'iterator_apply() on object Traversable is not supported by AOT in this compiler build'
            );
        }

        throw new \LogicException(
            'iterator_apply() argument must be an array or Traversable in this compiler build'
        );
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
        $receiver = self::normalizeObjectReceiver($context, $iterable);
        self::invokeIteratorMethod($context, $receiver, 'rewind');
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
        $valid = self::invokeIteratorMethodBool($context, $receiver, 'valid');
        $context->builder->branchIf($valid, $body, $done);
        $context->builder->positionAtEnd($body);
        $cur = $context->builder->load($countSlot);
        $context->builder->store(
            $context->builder->add($cur, $context->getTypeFromString('int64')->constInt(1, false)),
            $countSlot
        );
        self::invokeIteratorMethod($context, $receiver, 'next');
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
        $keep = self::callbackTruthy($context, $result);
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
        $keep = self::callbackTruthy($context, $result);
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
        $receiver = self::normalizeObjectReceiver($context, $iterable);
        self::invokeIteratorMethod($context, $receiver, 'rewind');
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
        $valid = self::invokeIteratorMethodBool($context, $receiver, 'valid');
        $context->builder->branchIf($valid, $body, $done);
        $context->builder->positionAtEnd($body);
        $value = self::invokeIteratorMethodValue($context, $receiver, 'current');
        $key = self::invokeIteratorMethodValue($context, $receiver, 'key');
        $result = $closureCall->call($context, $value, $key);
        $keep = self::callbackTruthy($context, $result);
        $advance = $fn->appendBasicBlock('iterator_apply_obj_advance');
        $context->builder->branchIf($keep, $advance, $done);
        $context->builder->positionAtEnd($advance);
        $cur = $context->builder->load($countSlot);
        $context->builder->store(
            $context->builder->add($cur, $context->getTypeFromString('int64')->constInt(1, false)),
            $countSlot
        );
        self::invokeIteratorMethod($context, $receiver, 'next');
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);

        return $context->builder->load($countSlot);
    }

    private static function callbackTruthy(Context $context, Value $result): Value
    {
        $ty = $context->getStringFromType($result->typeOf());
        if ('int1' === $ty) {
            return $result;
        }
        if ('int64' === $ty || 'int32' === $ty) {
            return $context->builder->icmp(Builder::INT_NE, $result, $context->getTypeFromString('int64')->constInt(0, false));
        }
        $boxed = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $result);

        return (new boolval())->call($context, $boxed);
    }

    private static function normalizeObjectReceiver(Context $context, Variable $iterable): Variable
    {
        if (Variable::TYPE_OBJECT === $iterable->type) {
            return $iterable;
        }
        if (Variable::TYPE_VALUE === $iterable->type) {
            $obj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $iterable)
            );

            return new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        }

        throw new \LogicException('iterator protocol requires an object traversable');
    }

    /**
     * @return array<int, Call>
     */
    private static function methodCandidates(Context $context, string $methodLc): array
    {
        $methodLc = strtolower($methodLc);
        $candidates = [];
        foreach ($context->type->object->allClassNamesById() as $classId => $className) {
            $classLc = strtolower(ltrim($className, '\\'));
            $current = $classLc;
            $visited = [];
            while (!isset($visited[$current])) {
                $visited[$current] = true;
                $proxyName = $current.'::'.$methodLc;
                if ($context->functionIsRegistered($proxyName)) {
                    $candidates[$classId] = $context->resolveFunctionProxy($proxyName);
                    break;
                }
                $current = $context->type->object->parentClassLc($current);
                if (null === $current) {
                    break;
                }
            }
        }

        return $candidates;
    }

    private static function resolveIteratorMethodProxy(Context $context, Variable $receiver, string $methodLc): Call
    {
        $methodLc = strtolower($methodLc);
        if (null !== $receiver->userType && '' !== $receiver->userType) {
            $classLc = strtolower(ltrim($receiver->userType, '\\'));
            if ('object' !== $classLc) {
                $proxyName = $classLc.'::'.$methodLc;
                if ($context->functionIsRegistered($proxyName)) {
                    return $context->resolveFunctionProxy($proxyName);
                }
            }
        }
        $candidates = self::methodCandidates($context, $methodLc);
        if (1 === \count($candidates)) {
            return reset($candidates);
        }
        if ([] === $candidates) {
            throw new \LogicException("iterator protocol method {$methodLc}() is not available in this compile unit");
        }

        throw new \LogicException(
            "iterator protocol method {$methodLc}() on a polymorphic object is not supported in this compiler build"
        );
    }

    private static function invokeIteratorMethod(Context $context, Variable $receiver, string $methodLc): void
    {
        $proxy = self::resolveIteratorMethodProxy($context, $receiver, $methodLc);
        $proxy->call($context, $receiver);
    }

    private static function invokeIteratorMethodBool(Context $context, Variable $receiver, string $methodLc): Value
    {
        $proxy = self::resolveIteratorMethodProxy($context, $receiver, $methodLc);
        $result = $proxy->call($context, $receiver);

        return self::callbackTruthy($context, $result);
    }

    private static function invokeIteratorMethodValue(Context $context, Variable $receiver, string $methodLc): Variable
    {
        $proxy = self::resolveIteratorMethodProxy($context, $receiver, $methodLc);
        $result = $proxy->call($context, $receiver);
        if ('int1' === $context->getStringFromType($result->typeOf())) {
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeBool($context, $slot, $result);

            return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
        }
        if ('int64' === $context->getStringFromType($result->typeOf())) {
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeLong($context, $slot, $result);

            return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
        }

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $result);
    }
}
