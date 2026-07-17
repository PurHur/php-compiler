<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
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
        // Z_PARAM_STR — null TypeError on 8.4 forward profile (#20174, ext/standard/password.c)
        $password = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'password_verify', 0, 'password');
        $hash = VmString::zparamStrBuiltinArgForFrame($frame, 1, 'password_verify', 1, 'hash');
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
            // Z_PARAM_STR — null TypeError on 8.4 forward profile (#20174, ext/standard/password.c)
            JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'password_verify', 0, 'password'),
            JitStringBuiltinArg::lowerZparamStr($context, $args[1], 'password_verify', 1, 'hash')
        );
    }
}
