<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\PHP;
use PHPCompiler\JIT\ArrayReduceCallbackPolicy;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\Variable;

/**
 * Shared array_reduce() callback validation and resolution (#6683, #6679).
 *
 * php-src: ext/standard/array.c — callback checked before empty-array short-circuit.
 */
final class VmReduceCallback
{
    /**
     * @return array{0: ?ClosureState, 1: ?PHP}
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
        try {
            return [null, VmUserCall::resolveStringCallback($frame->vmContext, $callback->toString())];
        } catch (\LogicException) {
            throw new \TypeError(
                ArrayReduceCallbackPolicy::invalidStringCallbackTypeError($callback->toString())
            );
        }
    }
}
