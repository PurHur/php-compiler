<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\UsortRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * usort() with Zend-callable comparators (ext/standard/array.c).
 *
 * VM: strcmp-family builtins, closures, invokables, array callables, user function names (#23550).
 * JIT/AOT: strcmp and closure/arrow comparators (packed sort; issue #3597).
 */
final class usort_ extends Internal
{
    public function __construct()
    {
        parent::__construct('usort');
    }

    public function execute(Frame $frame): void
    {
        VmArraySort::assertUserSortArgCount($frame, 'usort');
        $descending = VmArraySort::resolveUserSortDescending($frame, 'usort');
        $ht = VmArray::requireArray($frame->calledArgs[0], 'usort');
        $array = $frame->calledArgs[0]->resolveIndirect();
        $callback = $frame->calledArgs[1]->resolveIndirect();
        VmArraySortCallback::requireCallback($callback, 'usort');
        VmArraySortCallback::rejectInvalidStringCallback($frame, $callback, 'usort');
        VmArraySortCallback::requireVmCallable($frame, $callback, 'usort');
        $n = $ht->getNumElements();
        if (0 === $n) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }
        if (1 === $n) {
            // php-src still assigns new keys 0..n-1 for a single element (#25385).
            $array->separateArrayForWrite();
            VmArray::reindexToListKeys($array->resolveIndirect()->toArray());
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }
        $values = [];
        foreach ($ht->iterate(true) as $value) {
            $copy = new Variable();
            $copy->duplicateFrom($value);
            $values[] = $copy;
        }
        if (VmArraySortCallback::isStrcmpFamilyCallback($callback)) {
            $compare = VmInternalCompare::resolveStringCallback($callback->toString());
            if ($descending) {
                VmInternalCompare::sortVariableValuesDesc($values, $compare);
            } else {
                VmInternalCompare::sortVariableValues($values, $compare);
            }
        } else {
            if (null === $frame->vmContext) {
                throw new \LogicException('usort() requires VM context in this compiler build');
            }
            VmArraySortCallback::sortVariableValues(
                $frame->vmContext,
                $values,
                $callback,
                $descending,
                $frame,
                'usort'
            );
        }
        $array->separateArrayForWrite();
        VmArray::writeReindexedValues($array->resolveIndirect()->toArray(), $values);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('usort() requires exactly two arguments');
        }
        // php-src Z_PARAM_ARRAY — catchable TypeError under AOT try/catch (#27510).
        ExceptionBridge::ensureLinked($context);
        JitArrayElem::requireArrayParam($context, $args[0], 'usort', 1, 'array');
        if ($args[0]->type & JITVariable::IS_NATIVE_ARRAY
            || JITVariable::TYPE_HASHTABLE === $args[0]->type
            || JITVariable::TYPE_VALUE === $args[0]->type
        ) {
            return UsortRuntime::usortPacked($context, $args[0], $args[1]);
        }

        // Static non-array types already raised above; poison bool return for SSA.
        return $context->getTypeFromString('int1')->constInt(1, false);
    }
}
