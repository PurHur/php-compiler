<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** error_reporting() — get/set active error level (ext/standard/basic_functions.c; issue #3220). */
final class error_reporting extends Internal
{
    public function __construct()
    {
        parent::__construct('error_reporting');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('error_reporting() accepts at most one argument');
        }
        if (null === $frame->vmContext || null === $frame->returnVar) {
            return;
        }
        $old = VmIni::errorReporting($frame->vmContext);
        if (1 === $argc) {
            $level = VmMath::parseNullableIntBuiltinArgForFrame(
                $frame,
                0,
                'error_reporting',
                1,
                'error_level'
            );
            if (null !== $level) {
                VmIni::errorReporting($frame->vmContext, $level);
            }
        }
        $frame->returnVar->int($old);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \LogicException('error_reporting() accepts at most one argument');
        }

        return JitErrorReporting::invoke($context, $argc >= 1 ? $args[0] : null);
    }
}
