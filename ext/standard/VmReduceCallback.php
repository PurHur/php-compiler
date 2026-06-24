<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayReduceCallbackPolicy;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\Variable;

/**
 * Shared array_reduce() callback validation and resolution (#6683, #6679, #11057).
 *
 * php-src: ext/standard/array.c — callback checked before empty-array short-circuit.
 */
final class VmReduceCallback
{
    /**
     * @return array{0: ?ClosureState, 1: Internal|Func\PHP|null}
     */
    public static function resolve(Frame $frame, Variable $callback): array
    {
        $callback = $callback->resolveIndirect();
        if (Variable::TYPE_NULL === $callback->type) {
            throw new \TypeError(ArrayReduceCallbackPolicy::invalidCallbackTypeError());
        }
        if (VmClosureCall::isClosure($callback)) {
            return [VmClosureCall::resolve($callback), null];
        }
        if (Variable::TYPE_STRING !== $callback->type) {
            throw new \TypeError(ArrayReduceCallbackPolicy::invalidCallbackTypeError());
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('array_reduce() requires VM context in this compiler build');
        }
        $name = $callback->toString();
        try {
            return [null, VmInternalCall::resolveStringCallback($name)];
        } catch (\LogicException) {
        }
        try {
            return [null, VmUserCall::resolveStringCallback($frame->vmContext, $name)];
        } catch (\LogicException) {
            throw new \TypeError(
                ArrayReduceCallbackPolicy::invalidStringCallbackTypeError($name)
            );
        }
    }
}
