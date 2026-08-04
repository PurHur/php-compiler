<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\UsortRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\UsortCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * uasort() — sort by values preserving keys (ext/standard/array.c; issue #1211).
 *
 * VM: strcmp-family, closures, invokables, array callables, user function names (#23550).
 * JIT/AOT: compile-time strcmp or closure comparator preserving keys (#5698).
 */
final class uasort_ extends Internal
{
    public function __construct()
    {
        parent::__construct('uasort');
    }

    public function execute(Frame $frame): void
    {
        VmArraySort::assertUserSortArgCount($frame, 'uasort');
        $descending = VmArraySort::resolveUserSortDescending($frame, 'uasort');
        $ht = VmArray::requireArray($frame->calledArgs[0], 'uasort');
        $array = $frame->calledArgs[0]->resolveIndirect();
        $callback = $frame->calledArgs[1]->resolveIndirect();
        VmArraySortCallback::requireCallback($callback, 'uasort');
        VmArraySortCallback::rejectInvalidStringCallback($frame, $callback, 'uasort');
        VmArraySortCallback::requireVmCallable($frame, $callback, 'uasort');
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
        if (VmArraySortCallback::isStrcmpFamilyCallback($callback)) {
            $compare = VmInternalCompare::resolveStringCallback($callback->toString());
            if ($descending) {
                VmInternalCompare::sortKeyedPairsByValueDesc($pairs, $compare);
            } else {
                VmInternalCompare::sortKeyedPairsByValue($pairs, $compare);
            }
        } else {
            if (null === $frame->vmContext) {
                throw new \LogicException('uasort() requires VM context in this compiler build');
            }
            VmArraySortCallback::sortKeyedPairsByValue(
                $frame->vmContext,
                $pairs,
                $callback,
                $descending,
                $frame,
                'uasort'
            );
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
            throw new \LogicException('uasort() requires exactly two arguments');
        }
        if (!UsortCallbackPolicy::isJitLowerable($args[1])) {
            throw new \LogicException(UsortCallbackPolicy::jitRejectionMessage());
        }
        // php-src Z_PARAM_ARRAY — catchable TypeError under AOT try/catch (#27510 peer).
        ExceptionBridge::ensureLinked($context);
        JitArrayElem::requireArrayParam($context, $args[0], 'uasort', 1, 'array');
        if ($args[0]->type & JITVariable::IS_NATIVE_ARRAY
            || JITVariable::TYPE_HASHTABLE === $args[0]->type
            || JITVariable::TYPE_VALUE === $args[0]->type
        ) {
            return UsortRuntime::uasortValues($context, $args[0], $args[1]);
        }

        return $context->getTypeFromString('int1')->constInt(1, false);
    }
}
