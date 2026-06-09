<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** crypt() — POSIX DES/BCRYPT via libcrypt (issue #3771; php-src: ext/standard/crypt.c). */
final class crypt extends Internal
{
    public function __construct()
    {
        parent::__construct('crypt');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('crypt() requires exactly two arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $password = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'crypt', 0, 'password');
        $salt = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'crypt', 1, 'salt');
        $frame->returnVar->string(
            VmPassword::crypt($password, $salt)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('crypt() requires exactly two arguments');
        }

        return JitPassword::crypt(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'crypt', 0, 'password'),
            JitStringBuiltinArg::lower($context, $args[1], 'crypt', 1, 'salt')
        );
    }
}
