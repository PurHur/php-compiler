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
 * is_callable() — whether a value is a valid callback (issue #3132).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(is_callable)
 */
final class is_callable extends Internal
{
    public function __construct()
    {
        parent::__construct('is_callable');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            // Zend ZEND_ARG_VARIADIC_TYPE_INFO / min 1: "expects at least 1 argument, N given" (#21961)
            throw new \ArgumentCountError(\sprintf(
                'is_callable() expects at least 1 argument, %d given',
                $argc
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'is_callable() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $syntaxOnly = false;
        if ($argc >= 2) {
            $syntaxOnly = $frame->calledArgs[1]->resolveIndirect()->toBool();
        }
        $nameOut = ($argc >= 3) ? $frame->calledArgs[2] : null;
        $frame->returnVar->bool(
            VmCallable::isCallable($ctx, $frame->calledArgs[0], $syntaxOnly, $nameOut, $frame)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Standalone AOT: bare int1 from runtime array probes mis-lowers in ?: (#15704 / #27173).
        return $this->boxStandaloneBoolJitResult(
            $context,
            JitIsCallable::invoke($context, ...$args)
        );
    }
}
