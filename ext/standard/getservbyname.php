<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * getservbyname() — service port by name+protocol (JIT/AOT via libc, issue #4024).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/network.c PHP_FUNCTION(getservbyname)
 */
final class getservbyname extends Internal
{
    public function __construct()
    {
        parent::__construct('getservbyname');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('getservbyname() requires exactly two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $service = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'getservbyname',
            0,
            'service'
        );
        $protocol = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'getservbyname',
            1,
            'protocol'
        );
        $port = VmNetworkServices::getservbyname($service, $protocol);
        if (false === $port) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($port);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('getservbyname() requires exactly two arguments in this compiler build');
        }

        return JitNetworkServices::getservbyname($context, $args[0], $args[1]);
    }
}
