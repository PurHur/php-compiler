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
 * inet_ntop() — binary address to printable form (ext/standard/basic_functions.c, #3225).
 *
 * php-src stub names the parameter `$ip` (not historical `$in_addr`) — #29785 / #28916.
 */
final class inet_ntop extends Internal
{
    public function __construct()
    {
        parent::__construct('inet_ntop');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('inet_ntop() requires exactly one argument in this compiler build');
        }
        // Z_PARAM_STR — caller strict_types → TypeError on null; else soft-null (#29785 / #20303).
        $in_addr = VmString::stringBuiltinArgForFrame($frame, 0, 'inet_ntop', 0, 'ip', false);
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmInet::inet_ntop($in_addr);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('inet_ntop() requires exactly one argument in this compiler build');
        }
        // Soft-null outside strict_types; strict → TypeError (#29785). Param name `$ip` (php-src stub).
        $in_addr = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'inet_ntop', 0, 'ip')
            : JitStringBuiltinArg::lower($context, $args[0], 'inet_ntop', 0, 'ip', 'string', null, false);

        return JitInet::inetNtop($context, $in_addr);
    }
}
