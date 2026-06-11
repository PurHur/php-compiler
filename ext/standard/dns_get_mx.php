<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * dns_get_mx() — MX record lookup with by-ref host/weight arrays (#4125).
 *
 * VM: VmDns::dnsGetMx() via libc res_query FFI. JIT/AOT: VM-only v1.
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/dns.c PHP_FUNCTION(dns_get_mx)
 */
final class dns_get_mx extends Internal
{
    public function __construct()
    {
        parent::__construct('dns_get_mx');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \LogicException('dns_get_mx() requires exactly three arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $hostname = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'dns_get_mx', 0, 'hostname');
        VmDnsMx::validateArrayByRefArg($frame->calledArgs[1], 'dns_get_mx', 1, 'mxhosts');
        VmDnsMx::validateArrayByRefArg($frame->calledArgs[2], 'dns_get_mx', 2, 'weight');

        $ok = VmDnsMx::applyMxByRef(
            VmDns::dnsGetMx($hostname),
            $frame->calledArgs[1],
            $frame->calledArgs[2]
        );
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('dns_get_mx() is not implemented for JIT in this compiler build (issue #4125)');
    }
}
