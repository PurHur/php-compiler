<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * ip2long() — IPv4 string to 32-bit integer (ext/standard/basic_functions.c, #3225).
 */
final class ip2long extends Internal
{
    public function __construct()
    {
        parent::__construct('ip2long');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('ip2long() requires exactly one argument in this compiler build');
        }
        // Z_PARAM_STR — caller strict_types → TypeError on null; else soft-null (#29785).
        $ip = VmString::stringBuiltinArgForFrame($frame, 0, 'ip2long', 0, 'ip', false);
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmInet::ip2long($ip);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('ip2long() requires exactly one argument in this compiler build');
        }
        // Soft-null outside strict_types; strict → TypeError (#29785).
        $ip = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'ip2long', 0, 'ip')
            : JitStringBuiltinArg::lower($context, $args[0], 'ip2long', 0, 'ip', 'string', null, false);

        return JitInet::ip2long($context, $ip);
    }
}
