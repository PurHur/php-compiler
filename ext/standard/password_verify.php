<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** password_verify() — VM/JIT/AOT via VmPasswordNative libcrypt (issues #172, #4794, #6906). */
final class password_verify extends Internal
{
    public function __construct()
    {
        parent::__construct('password_verify');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('password_verify() requires exactly two arguments');
        }
        $password = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'password_verify', 0, 'password');
        $hash = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'password_verify', 1, 'hash');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(
            VmPassword::verify($password, $hash)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('password_verify() requires exactly two arguments');
        }

        return JitPassword::verify(
            $context,
            JitStringArg::lowerDominating($context, $args[0], 'password_verify() password'),
            JitStringArg::lowerDominating($context, $args[1], 'password_verify() hash')
        );
    }
}
