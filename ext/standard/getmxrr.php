<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * getmxrr() — MX record lookup (ext/standard/dns.c parity, #3662).
 *
 * VM: VmDns::dnsGetMx(). JIT/AOT: VM-only v1.
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/dns.c PHP_FUNCTION(getmxrr)
 */
final class getmxrr extends Internal
{
    public function __construct()
    {
        parent::__construct('getmxrr');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('getmxrr() requires two or three arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $hostname = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'getmxrr', 0, 'hostname');
        VmDnsMx::validateArrayByRefArg($frame->calledArgs[1], 'getmxrr', 1, 'mxhosts');
        $weightsArg = null;
        if ($argc >= 3) {
            VmDnsMx::validateArrayByRefArg($frame->calledArgs[2], 'getmxrr', 2, 'weight');
            $weightsArg = $frame->calledArgs[2];
        }

        $ok = VmDnsMx::applyMxByRef(
            VmDns::dnsGetMx($hostname),
            $frame->calledArgs[1],
            $weightsArg
        );
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('getmxrr() is not implemented for JIT in this compiler build (issue #3662)');
    }
}
