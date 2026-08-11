<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_all() — PHP 8.4: returns true if callback returns true for all elements.
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_all).
 */
final class array_all extends Internal
{
    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'array_all', 2, 2);
        $src = VmArray::requireArrayParam($frame->calledArgs[0], 'array_all', 1, 'array');
        $callbackArg = $frame->calledArgs[1];

        if (null === $frame->vmContext) {
            throw new \LogicException('array_all() requires VM context');
        }

        [$closure, $internal, $userFn, $general] = VmArrayFilterCallback::resolve($frame, $callbackArg);

        foreach ($src->iterateKeyed(true) as [$key, $value]) {
            $result = ArrayCallbackInvoke::invoke(
                $frame,
                $closure,
                $internal,
                $userFn,
                $general,
                $value,
                $key
            );
            if (!boolval::isTruthy($result)) {
                if (null !== $frame->returnVar) {
                    $frame->returnVar->bool(false);
                }

                return;
            }
        }

        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('array_all() is not supported by the JIT compiler in this build');
    }
}
