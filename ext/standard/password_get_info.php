<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** password_get_info() — hash metadata (ext/standard/password.c, issue #3649). */
final class password_get_info extends Internal
{
    public function __construct()
    {
        parent::__construct('password_get_info');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('password_get_info() requires exactly one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $hash = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'password_get_info',
            0,
            'hash'
        );
        $frame->returnVar->array(
            VmPassword::infoToHashTable(VmPassword::getInfo($hash))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('password_get_info() requires exactly one argument');
        }

        return JitPasswordGetInfo::invoke(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'password_get_info', 0, 'hash')
        );
    }
}
