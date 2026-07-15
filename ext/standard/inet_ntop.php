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
        $in_addr = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[0], 'inet_ntop', 0, 'in_addr');
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

        return JitInet::inetNtop(
            $context,
            JitStringBuiltinArg::lowerTypedString($context, $args[0], 'inet_ntop', 0, 'in_addr')
        );
    }
}
