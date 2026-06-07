<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * getprotobyname() — protocol number by name (JIT/AOT via libc, issue #4024).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/network.c PHP_FUNCTION(getprotobyname)
 */
final class getprotobyname extends Internal
{
    public function __construct()
    {
        parent::__construct('getprotobyname');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('getprotobyname() requires exactly one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $name = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'getprotobyname',
            0,
            'protocol'
        );
        $number = VmNetworkServices::getprotobyname($name);
        if (false === $number) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($number);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('getprotobyname() requires exactly one argument in this compiler build');
        }

        return JitNetworkServices::getprotobyname($context, $args[0]);
    }
}
