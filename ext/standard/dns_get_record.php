<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * dns_get_record() — DNS record lookup (ext/standard/dns.c parity, #6392).
 *
 * Z_PARAM_STR $hostname — null TypeError under caller strict_types only; PROFILE=8.4 soft-null DEP+coerce
 * (#24965 / re-#24178, reverts #23858 over-strict; php-src ext/standard/dns.c).
 *
 * VM: VmDns::dnsGetRecord(). JIT/AOT: JitDnsGetRecord compile-time materializer.
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/dns.c PHP_FUNCTION(dns_get_record)
 */
final class dns_get_record extends Internal
{
    public function __construct()
    {
        parent::__construct('dns_get_record');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('dns_get_record() requires one to four arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $hostname = VmString::trimFamilyStringArgForFrame($frame, 0, 'dns_get_record', 0, 'hostname');
        $type = StdlibConstants::DNS_A;
        if ($argc >= 2) {
            $typeVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_ARRAY === $typeVar->type) {
                throw new \TypeError(VmString::stringBuiltinTypeError('dns_get_record', 1, 'type', 'array'));
            }
            $type = VmMath::parseIntBuiltinArg($typeVar, 'dns_get_record', 1, 'type');
        }

        if ($argc >= 3) {
            self::clearOptionalByRefArray($frame->calledArgs[2]);
        }
        if ($argc >= 4) {
            self::clearOptionalByRefArray($frame->calledArgs[3]);
        }

        $result = VmDns::dnsGetRecord($hostname, $type);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->array($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('dns_get_record() requires one to four arguments in this compiler build');
        }

        $hostnameArg = $args[0];
        $nullOperand = JITVariable::TYPE_NULL === $hostnameArg->type
            || ($hostnameArg->isNullConstant ?? false);
        if ($nullOperand && $context->callerStrictTypes) {
            JitStringBuiltinArg::lowerZparamStr($context, $hostnameArg, 'dns_get_record', 0, 'hostname');
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        // Compile-time null → "" without DEP IR (AOT-safe; VM emits DEP) (#24965).
        if ($nullOperand) {
            return JitDnsGetRecord::invokeLiteral(
                $context,
                '',
                $argc >= 2 ? $args[1] : null,
                $argc >= 3 ? $args[2] : null,
                $argc >= 4 ? $args[3] : null
            );
        }

        JitStringBuiltinArg::lowerTrimFamilyString($context, $hostnameArg, 'dns_get_record', 0, 'hostname');

        $literal = JitStringArg::compileTimeLiteral($hostnameArg);
        if (null === $literal) {
            throw new \LogicException(
                'dns_get_record() requires compile-time string hostname for JIT/AOT in this build'
            );
        }

        return JitDnsGetRecord::invokeLiteral(
            $context,
            $literal,
            $argc >= 2 ? $args[1] : null,
            $argc >= 3 ? $args[2] : null,
            $argc >= 4 ? $args[3] : null
        );
    }

    private static function clearOptionalByRefArray(\PHPCompiler\VM\Variable $arg): void
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arg->type) {
            return;
        }
        $empty = new Variable(Variable::TYPE_ARRAY);
        $empty->array(new \PHPCompiler\VM\HashTable());
        $arg->copyFrom($empty);
    }
}
