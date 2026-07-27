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
 * gethostbynamel() — IPv4 address list for hostname (ext/standard/dns.c parity, #3707).
 *
 * Z_PARAM_STR $hostname — null TypeError on 8.4 forward profile (#20555, re-#19098).
 *
 * VM: VmDns (libc FFI, #4928). JIT/AOT: GethostbynamelRuntime → GethostbynamelJitHelper PHP (#9382).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/dns.c PHP_FUNCTION(gethostbynamel)
 */
final class gethostbynamel extends Internal
{
    public function __construct()
    {
        parent::__construct('gethostbynamel');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('gethostbynamel() requires exactly one argument in this compiler build');
        }
        $hostname = VmString::coerceZparamStrBuiltinArg(
            $frame->calledArgs[0],
            'gethostbynamel',
            0,
            'hostname'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmDns::gethostbynamel($hostname);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->array($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('gethostbynamel() requires exactly one argument in this compiler build');
        }

        $hostnameArg = $args[0];
        $nullOperand = JITVariable::TYPE_NULL === $hostnameArg->type
            || ($hostnameArg->isNullConstant ?? false);
        if ($nullOperand && (VmString::requiresZparamStrStrictNullOnForwardProfile() || $context->callerStrictTypes)) {
            JitStringBuiltinArg::lowerZparamStr(
                $context,
                $hostnameArg,
                'gethostbynamel',
                0,
                'hostname'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        GethostbynamelRuntime::ensureLinked($context);
        $hostname = JitStringBuiltinArg::lowerZparamStr(
            $context,
            $hostnameArg,
            'gethostbynamel',
            0,
            'hostname'
        );

        return JitGethostbynamel::invoke($context, $hostname);
    }
}
