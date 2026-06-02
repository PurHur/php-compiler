<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
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
        $password = $frame->calledArgs[0]->resolveIndirect();
        $salt = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $password->type || Variable::TYPE_STRING !== $salt->type) {
            throw new \LogicException('crypt() requires string password and salt in this compiler build');
        }
        $frame->returnVar->string(
            VmPassword::crypt($password->toString(), $salt->toString())
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('crypt() requires exactly two arguments');
        }

        return JitPassword::crypt(
            $context,
            JitStringArg::lower($context, $args[0], 'crypt() password'),
            JitStringArg::lower($context, $args[1], 'crypt() salt')
        );
    }
}
