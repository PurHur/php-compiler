<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * dns_get_mx() — MX record lookup into by-ref host/weight lists (#4125).
 *
 * VM: VmDns (UDP DNS + optional res_query FFI). JIT/AOT: JitDnsGetMxMaterializer for literals.
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
        $fn = $this->getName();
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects between 2 and 3 arguments, %d given',
                $fn,
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $hostname = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $fn, 0, 'hostname');
        $hostsArg = $frame->calledArgs[1];
        self::requireByRefArrayArg($hostsArg, $fn, 2, 'mxhosts');

        $weightsArg = null;
        if ($argc >= 3) {
            $weightsArg = $frame->calledArgs[2];
            self::requireByRefArrayArg($weightsArg, $fn, 3, 'weight');
        }

        $result = VmDns::dnsGetMx($hostname);
        $hostsVar = new Variable(Variable::TYPE_ARRAY);
        $hostsVar->array($result['hosts']);
        $hostsArg->copyFrom($hostsVar);

        if (null !== $weightsArg) {
            $weightsVar = new Variable(Variable::TYPE_ARRAY);
            $weightsVar->array($result['weights']);
            $weightsArg->copyFrom($weightsVar);
        }

        $frame->returnVar->bool($result['ok']);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $fn = $this->getName();
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException($fn.'() expects between 2 and 3 arguments in this compiler build');
        }

        $weightsArg = $argc >= 3 ? $args[2] : null;

        return JitDnsGetMx::invoke($context, $args[0], $args[1], $weightsArg);
    }

    private static function requireByRefArrayArg(Variable $arg, string $fn, int $argNum, string $paramName): void
    {
        $resolved = $arg->resolveIndirect();
        if (
            Variable::TYPE_ARRAY !== $resolved->type
            && Variable::TYPE_UNDEFINED !== $resolved->type
            && Variable::TYPE_NULL !== $resolved->type
        ) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type array, %s given',
                $fn,
                $argNum,
                $paramName,
                VmParseStr::zendTypeLabel($resolved)
            ));
        }
    }
}
