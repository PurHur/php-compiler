<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * gettimeofday() — wall-clock array or float (VM VmDate; JIT GettimeofdayJitHelper PHP, #13764).
 */
final class gettimeofday extends Internal
{
    public function __construct()
    {
        parent::__construct('gettimeofday');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('gettimeofday() accepts at most one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $asFloat = false;
        if (1 === $argc) {
            $asFloat = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[0],
                'gettimeofday',
                1,
                'as_float'
            );
        }
        if ($asFloat) {
            $frame->returnVar->float(VmDate::gettimeofdayFloat());

            return;
        }
        $frame->returnVar->array(VmDate::gettimeofdayArray());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('gettimeofday() accepts at most one argument');
        }
        $asFloat = $context->constantFromBool(false);
        if (isset($args[0])) {
            $asFloat = JitBoolArg::lower($context, $args[0], 'gettimeofday(): Argument #1 ($as_float)');
        }

        return JitGettimeofday::call($context, $asFloat);
    }
}
