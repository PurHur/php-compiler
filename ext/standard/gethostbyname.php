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
 * Z_PARAM_STR $hostname — null TypeError under caller strict_types only; PROFILE=8.4 soft-null DEP+coerce
 * (#24965 / re-#24178, reverts #23858 over-strict; php-src ext/standard/dns.c).
 *
 * VM: VmDns (reuses gethostbynamel getaddrinfo path). JIT/AOT: JitGethostbyname LLVM delegate.
 * Excess/missing argc → Zend ArgumentCountError (#30585).
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
        // php-src stub arity: exactly 1 (#30585; ext/standard/dns.c).
        $this->requireExactArgCount($frame, 'gethostbyname', 1);
        $hostname = VmString::trimFamilyStringArgForFrame($frame, 0, 'gethostbyname', 0, 'hostname');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmDns::gethostbyname($hostname));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30585).
        if (!$this->requireExactJitArgCount($context, $args, 'gethostbyname', 1)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }

        $hostnameArg = $args[0];
        $nullOperand = JITVariable::TYPE_NULL === $hostnameArg->type
            || ($hostnameArg->isNullConstant ?? false);
        if ($nullOperand && $context->callerStrictTypes) {
            JitStringBuiltinArg::lowerZparamStr($context, $hostnameArg, 'gethostbyname', 0, 'hostname');
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        // Compile-time null → "" without DNS helper / DEP IR (AOT-safe fold; VM emits DEP) (#24965).
        if ($nullOperand) {
            return self::boxedEmptyString($context);
        }

        GethostbynamelRuntime::ensureLinked($context);
        $hostname = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $hostnameArg,
            'gethostbyname',
            0,
            'hostname'
        );

        return JitGethostbyname::invoke($context, $hostname);
    }

    private static function boxedEmptyString(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $empty = $context->builder->load($context->constantStringFromString(''));
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $empty
        );

        return $ptr;
    }
}
