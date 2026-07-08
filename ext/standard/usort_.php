<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\UsortRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\UsortCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * usort() with string builtin comparators (subset of PHP).
 *
 * VM: strcmp and strcasecmp on packed list arrays; closure comparators (#3086).
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
        if ($ht->getNumElements() < 2) {
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
        if (VmClosureCall::isClosure($callback)) {
            if (null === $frame->vmContext) {
                throw new \LogicException('usort() requires VM context in this compiler build');
            }
            VmClosureCall::sortVariableValues(
                $frame->vmContext,
                $values,
                VmClosureCall::resolve($callback),
                $descending
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
            if ($descending) {
                VmInternalCompare::sortVariableValuesDesc($values, $compare);
            } else {
                VmInternalCompare::sortVariableValues($values, $compare);
            }
        }
        $array->separateArrayForWrite();
        $ht = $array->resolveIndirect()->toArray();
        if (VmArray::isList($ht)) {
            $ht->replacePackedValues($values);
        } else {
            $ht->assignPackedList($values);
        }
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
        return UsortRuntime::usortPacked($context, $args[0], $args[1]);
    }
}
