<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\UsortRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * uksort() — sort by keys preserving values (ext/standard/array.c php_array_uksort; issue #3143).
 *
 * VM: strcmp-family, closures, invokables, array callables, user function names (#23550).
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
        VmArraySort::assertUserSortArgCount($frame, 'uksort');
        $descending = VmArraySort::resolveUserSortDescending($frame, 'uksort');
        $ht = VmArray::requireArray($frame->calledArgs[0], 'uksort');
        $array = $frame->calledArgs[0]->resolveIndirect();
        $callback = $frame->calledArgs[1]->resolveIndirect();
        VmArraySortCallback::requireCallback($callback, 'uksort');
        VmArraySortCallback::rejectInvalidStringCallback($frame, $callback, 'uksort');
        VmArraySortCallback::requireVmCallable($frame, $callback, 'uksort');
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
                VmInternalCompare::sortKeyedPairsByKeyWithCompareDesc($pairs, $compare);
            } else {
                VmInternalCompare::sortKeyedPairsByKeyWithCompare($pairs, $compare);
            }
        } else {
            if (null === $frame->vmContext) {
                throw new \LogicException('uksort() requires VM context in this compiler build');
            }
            VmArraySortCallback::sortKeyedPairsByKey(
                $frame->vmContext,
                $pairs,
                $callback,
                $descending,
                $frame,
                'uksort'
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
            throw new \LogicException('uksort() requires exactly two arguments');
        }
        // php-src Z_PARAM_ARRAY — catchable TypeError under AOT try/catch (#27510 peer).
        ExceptionBridge::ensureLinked($context);
        JitArrayElem::requireArrayParam($context, $args[0], 'uksort', 1, 'array');
        if ($args[0]->type & JITVariable::IS_NATIVE_ARRAY
            || JITVariable::TYPE_HASHTABLE === $args[0]->type
            || JITVariable::TYPE_VALUE === $args[0]->type
        ) {
            return UsortRuntime::uksortKeys($context, $args[0], $args[1]);
        }

        return $context->getTypeFromString('int1')->constInt(1, false);
    }
}
