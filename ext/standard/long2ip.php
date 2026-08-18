<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * long2ip() — IPv4 dotted-quad from 32-bit integer (ext/standard/basic_functions.c, #3225).
 *
 * php-src stub names the parameter `$ip` (not historical `$proper_address`) — #23357.
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
            throw new \ArgumentCountError('long2ip() expects exactly 1 argument, '.\count($frame->calledArgs).' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        // php-src stub names the parameter `$ip` (not historical `$proper_address`) — #23357.
        $properAddress = VmMath::parseChrCodepointForFrame($frame, 0, 'long2ip', 1, 'ip');
        $result = VmInet::long2ip($properAddress);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError('long2ip() expects exactly 1 argument, '.\count($args).' given');
        }

        return JitInet::long2ip(
            $context,
            JitChr::lowerZParamLongArg($context, $args[0], 'long2ip', 1, 'ip')
        );
    }
}
