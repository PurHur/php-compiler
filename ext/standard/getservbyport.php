<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * getservbyport() — service name by port (VM host; JIT/AOT via libc, issue #3650).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/network.c PHP_FUNCTION(getservbyport)
 */
final class getservbyport extends Internal
{
    public function __construct()
    {
        parent::__construct('getservbyport');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('getservbyport() requires exactly two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $port = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[0],
            'getservbyport',
            1,
            'port'
        );
        $protocol = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'getservbyport',
            1,
            'protocol'
        );
        $name = VmNetworkServices::getservbyport($port, $protocol);
        if (false === $name) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($name);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('getservbyport() requires exactly two arguments in this compiler build');
        }

        return JitNetworkServices::getservbyport($context, $args[0], $args[1]);
    }
}
