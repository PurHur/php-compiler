<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** putenv() — set/unset process environment (VM; JIT/AOT via libc putenv). */
final class putenv_ extends Internal
{
    public function __construct()
    {
        parent::__construct('putenv');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('putenv() requires exactly one argument');
        }
        $assignment = VmString::stringBuiltinArgForFrame($frame, 0, 'putenv', 0, 'assignment');
        $ok = VmEnv::putenv($assignment);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('putenv() requires exactly one argument');
        }
        $assignment = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[0],
            'putenv',
            0,
            'assignment'
        );
        // Dominating __string__* for concat/slot temps (syntax guard + setenv mirror) (#17316).
        $assignment = \PHPCompiler\JIT\JitStringArg::materializeStringDominating($context, $assignment);

        return JitEnv::putenv($context, $assignment);
    }
}
