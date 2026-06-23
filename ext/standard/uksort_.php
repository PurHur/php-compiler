<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\UsortCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * uksort() — sort by keys preserving values (ext/standard/array.c php_array_uksort; issue #3143).
 *
 * VM: strcmp and strcasecmp string callbacks; closure comparators (#3086).
 * JIT/AOT: compile-time strcmp or closure comparator on string-key hashtables (#3597).
 */
final class uksort_ extends Internal
{
    public function __construct()
    {
        parent::__construct('uksort');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('uksort() requires exactly two arguments');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('uksort() first argument must be an array in this compiler build');
        }
        $callback = $frame->calledArgs[1]->resolveIndirect();
        VmArraySortCallback::requireCallback($callback, 'uksort');
        $ht = $array->toArray();
        if ($ht->getNumElements() < 2) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }
        $pairs = [];
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $keyCopy = new Variable();
            $keyCopy->duplicateFrom($key);
            $valCopy = new Variable();
            $valCopy->duplicateFrom($value);
            $pairs[] = [$keyCopy, $valCopy];
        }
        if (VmClosureCall::isClosure($callback)) {
            if (null === $frame->vmContext) {
                throw new \LogicException('uksort() requires VM context in this compiler build');
            }
            VmClosureCall::sortKeyedPairsByKey(
                $frame->vmContext,
                $pairs,
                VmClosureCall::resolve($callback)
            );
        } else {
            if (!UsortCallbackPolicy::isVmSupportedType($callback->type)) {
                throw new \LogicException(UsortCallbackPolicy::vmRejectionMessage());
            }
            $name = $callback->toString();
            if (!UsortCallbackPolicy::isVmSupportedName($name)) {
                throw new \LogicException(UsortCallbackPolicy::vmRejectionMessage());
            }
            $compare = VmInternalCompare::resolveStringCallback($name);
            VmInternalCompare::sortKeyedPairsByKeyWithCompare($pairs, $compare);
        }
        $sorted = new HashTable();
        foreach ($pairs as [$key, $value]) {
            array_map::appendKeyedCopy($sorted, $key, $value);
        }
        $array->array($sorted);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('uksort() requires exactly two arguments');
        }
        if (!UsortCallbackPolicy::isJitLowerable($args[1])) {
            throw new \LogicException(UsortCallbackPolicy::jitRejectionMessage());
        }
        if (UsortCallbackPolicy::isClosureJitLowerable($args[1])) {
            ArrayBuiltinHelper::sortStringKeysWithClosure($context, $args[0], $args[1]);
        } else {
            ArrayBuiltinHelper::ksortByKey($context, $args[0]);
        }

        return $context->getTypeFromString('int1')->constInt(1, false);
    }
}
