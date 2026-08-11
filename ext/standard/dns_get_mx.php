<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * dns_get_mx() — MX record lookup with by-ref host/weight arrays (#4125, #29810).
 *
 * VM: VmDns::dnsGetMx() via res_query FFI + UDP DNS fallback. JIT/AOT: JitDnsGetMxMaterializer.
 * Z_PARAM_STR: strict_types → TypeError on null; soft path DEP+coerce (#29810; alias of getmxrr).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/dns.c PHP_FUNCTION(dns_get_mx)
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.stub.php string $hostname
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

        // Z_PARAM_STR — caller strict_types → TypeError on null; else soft-null (#29810).
        $hostname = VmString::stringBuiltinArgForFrame($frame, 0, 'dns_get_mx', 0, 'hostname', false);
        VmDnsMx::validateArrayByRefArg($frame->calledArgs[1], 'dns_get_mx', 1, 'hosts');
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

        // Soft-null outside strict_types; strict → TypeError (#29810).
        // Early return after compile-time null TypeError — no MX materializer after abort.
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'dns_get_mx', 0, 'hostname');

                return JitValueBox::pointer($context, JitValueBox::alloc($context));
            }
            JitStringBuiltinArg::lower($context, $args[0], 'dns_get_mx', 0, 'hostname', 'string', null, false);
            $empty = new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $context->builder->load($context->constantStringFromString(''))
            );
            $empty->compileTimeString = '';

            return JitDnsGetMx::invoke($context, $empty, $args[1], $weightsArg);
        }

        if ($context->callerStrictTypes) {
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'dns_get_mx', 0, 'hostname');
        } else {
            JitStringBuiltinArg::lower($context, $args[0], 'dns_get_mx', 0, 'hostname', 'string', null, false);
        }

        return JitDnsGetMx::invoke($context, $args[0], $args[1], $weightsArg);
    }
}
