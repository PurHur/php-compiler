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
 * dns_get_mx() — MX record lookup with by-ref host/weight arrays (#4125).
 *
 * VM: VmDns::dnsGetMx() via res_query FFI + UDP DNS fallback. JIT/AOT: JitDnsGetMxMaterializer.
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
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'dns_get_mx() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $hostname = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'dns_get_mx', 0, 'hostname');
        VmDnsMx::validateArrayByRefArg($frame->calledArgs[1], 'dns_get_mx', 1, 'mxhosts');
        $weightsArg = null;
        if ($argc >= 3) {
            // php-src dns.c — &$weight is overwritten; non-arrays are not TypeError (#22707).
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
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('dns_get_mx() expects between 2 and 3 arguments in this compiler build');
        }

        $weightsArg = $argc >= 3 ? $args[2] : null;

        JitStringBuiltinArg::lower($context, $args[0], 'dns_get_mx', 0, 'hostname');

        return JitDnsGetMx::invoke($context, $args[0], $args[1], $weightsArg);
    }
}
