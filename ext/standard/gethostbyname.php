<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\GethostbynamelRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * gethostbyname() — forward DNS returning first IPv4 (ext/standard/dns.c parity, #7419).
 *
 * Z_PARAM_STR $hostname — null TypeError on 8.4 forward profile (#20555, re-#19098).
 *
 * VM: VmDns (reuses gethostbynamel getaddrinfo path). JIT/AOT: JitGethostbyname LLVM delegate.
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/dns.c PHP_FUNCTION(gethostbyname)
 */
final class gethostbyname extends Internal
{
    public function __construct()
    {
        parent::__construct('gethostbyname');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('gethostbyname() requires exactly one argument in this compiler build');
        }
        $hostname = VmString::coerceZparamStrBuiltinArg(
            $frame->calledArgs[0],
            'gethostbyname',
            0,
            'hostname'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmDns::gethostbyname($hostname));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('gethostbyname() requires exactly one argument in this compiler build');
        }

        GethostbynamelRuntime::ensureLinked($context);
        $hostnameArg = $args[0];
        $hostname = JitStringBuiltinArg::lowerZparamStr(
            $context,
            $hostnameArg,
            'gethostbyname',
            0,
            'hostname'
        );
        $nullOperand = JITVariable::TYPE_NULL === $hostnameArg->type
            || ($hostnameArg->isNullConstant ?? false);
        if ($nullOperand && (VmString::requiresZparamStrStrictNullOnForwardProfile() || $context->callerStrictTypes)) {
            // TypeError already emitted; skip DNS IR after abort (#20555, dns_get_record pattern).
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitGethostbyname::invoke($context, $hostname);
    }
}
