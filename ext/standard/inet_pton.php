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
 * inet_pton() — printable address to binary form (ext/standard/basic_functions.c, #3225).
 */
final class inet_pton extends Internal
{
    public function __construct()
    {
        parent::__construct('inet_pton');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('inet_pton() requires exactly one argument in this compiler build');
        }
        $address = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[0], 'inet_pton', 0, 'address');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmInet::inet_pton($address);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('inet_pton() requires exactly one argument in this compiler build');
        }

        return JitInet::inetPton(
            $context,
            JitStringBuiltinArg::lowerTypedString($context, $args[0], 'inet_pton', 0, 'address')
        );
    }
}
