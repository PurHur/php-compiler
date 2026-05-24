<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayMapCallbackPolicy;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_walk() — in-place walk with string builtin callbacks (subset of PHP; issue #1209).
 *
 * JIT/AOT: deferred — use VM or compile-time string callbacks only in future (#1209).
 */
final class array_walk extends Internal
{
    public function __construct()
    {
        parent::__construct('array_walk');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('array_walk() requires two or three arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_walk() first argument must be an array in this compiler build');
        }
        $callback = $frame->calledArgs[1]->resolveIndirect();
        if (!ArrayMapCallbackPolicy::isVmSupportedType($callback->type)) {
            throw new \LogicException(ArrayMapCallbackPolicy::vmRejectionMessage());
        }
        $fn = VmInternalCall::resolveStringCallback($callback->toString());
        if (3 === $argc) {
            throw new \LogicException('array_walk() userdata is not supported in this compiler build');
        }
        $src = $array->toArray();
        $out = new HashTable();
        foreach ($src->iterateKeyed(true) as [$key, $value]) {
            $result = VmInternalCall::invoke($fn, $value);
            if (Variable::TYPE_BOOLEAN === $result->type && !$result->toBool()) {
                $frame->returnVar->bool(false);

                return;
            }
            if (Variable::TYPE_NULL !== $result->type) {
                array_map::appendKeyedCopy($out, $key, $result);
            } else {
                array_map::appendKeyedCopy($out, $key, $value);
            }
        }
        $array->array($out);
        $frame->returnVar->bool(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'array_walk() not implemented for JIT in this compiler build (issue #1209)'
        );
    }
}
