<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * long2ip() — IPv4 dotted-quad from 32-bit integer (ext/standard/basic_functions.c, #3225).
 */
final class long2ip extends Internal
{
    public function __construct()
    {
        parent::__construct('long2ip');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('long2ip() requires exactly one argument in this compiler build');
        }
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $arg->type) {
            throw new \LogicException('long2ip() argument must be an integer in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmInet::long2ip($arg->toInt());
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('long2ip() requires exactly one argument in this compiler build');
        }

        return JitInet::long2ip(
            $context,
            JitLongArg::lower($context, $args[0], 'long2ip() argument #1')
        );
    }
}
