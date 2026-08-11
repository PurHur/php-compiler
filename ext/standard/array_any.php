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
 * array_any() — PHP 8.4: returns true if callback returns true for any element.
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_any).
 */
final class array_any extends Internal
{
    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'array_any', 2, 2);
        $src = VmArray::requireArrayParam($frame->calledArgs[0], 'array_any', 1, 'array');
        $callbackArg = $frame->calledArgs[1];

        if (null === $frame->vmContext) {
            throw new \LogicException('array_any() requires VM context');
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
            if (boolval::isTruthy($result)) {
                if (null !== $frame->returnVar) {
                    $frame->returnVar->bool(true);
                }

                return;
            }
        }

        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(false);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('array_any() is not supported by the JIT compiler in this build');
    }
}
