<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
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
        $arg0 = $frame->calledArgs[0]->resolveIndirect();
        $arg1 = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_NULL === $arg0->type) {
            throw new \TypeError('crypt(): Argument #1 ($password) must be of type string, null given');
        }
        if (Variable::TYPE_NULL === $arg1->type) {
            throw new \TypeError('crypt(): Argument #2 ($salt) must be of type string, null given');
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
            JitStringBuiltinArg::lowerTypedString($context, $args[0], 'crypt', 0, 'password'),
            JitStringBuiltinArg::lowerTypedString($context, $args[1], 'crypt', 1, 'salt')
        );
    }
}
