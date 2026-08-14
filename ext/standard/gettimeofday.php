<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * gettimeofday() — wall-clock array or float (VM VmDate; JIT GettimeofdayJitHelper PHP, #13764).
 *
 * php-src: ext/standard/microtime.c — PHP_FUNCTION(gettimeofday)
 */
final class gettimeofday extends Internal
{
    public function __construct()
    {
        parent::__construct('gettimeofday');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/basic_functions.stub.php — ArgumentCountError (#30682).
        $this->requireAtMostArgCount($frame, 'gettimeofday', 1);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        $asFloat = false;
        if (1 === $argc) {
            $asFloat = VmMath::parseBoolBuiltinArgForFrame($frame, 0, 'gettimeofday', 1, 'as_float');
        }
        if ($asFloat) {
            $frame->returnVar->float(VmDate::gettimeofdayFloat());

            return;
        }
        $frame->returnVar->array(VmDate::gettimeofdayArray());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT) — peer microtime #28691 / #30682.
        if (!$this->requireAtMostJitArgCount($context, $args, 'gettimeofday', 1)) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }
        $asFloat = $context->constantFromBool(false);
        if (isset($args[0])) {
            $asFloat = JitBoolArg::lowerZParamBool($context, $args[0], 'gettimeofday', 'as_float', 1);
        }

        return JitGettimeofday::call($context, $asFloat);
    }
}
