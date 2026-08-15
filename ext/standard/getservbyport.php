<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * getservbyport() — service name by port (VM host; JIT/AOT via libc, issue #3650, #30283).
 *
 * Z_PARAM_LONG port: strict_types → TypeError on null; soft path DEP+coerce to 0 (#30283).
 * Excess/missing argc → Zend ArgumentCountError (#30567).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/network.c PHP_FUNCTION(getservbyport)
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.stub.php int $port, string $protocol
 */
final class getservbyport extends Internal
{
    public function __construct()
    {
        parent::__construct('getservbyport');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 2 (#30567; ext/standard/network.c).
        $this->requireExactArgCount($frame, 'getservbyport', 2);
        if (null === $frame->returnVar) {
            return;
        }
        // Z_PARAM_LONG — caller strict_types → TypeError on null; else soft-null → 0 (#30283).
        $port = VmMath::parseZParamLongBuiltinArgForFrame(
            $frame,
            0,
            'getservbyport',
            1,
            'port'
        );
        $protocol = VmString::stringBuiltinArgForFrame($frame, 1, 'getservbyport', 1, 'protocol', false);
        $name = VmNetworkServices::getservbyport($port, $protocol);
        if (false === $name) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($name);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30567).
        if (!$this->requireExactJitArgCount($context, $args, 'getservbyport', 2)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }

        // Soft-null outside strict_types; strict → TypeError (#30283).
        // Early return after compile-time null TypeError — no libc call after abort
        // (AOT module verify: terminator mid-block; peer getprotobyname #30282).
        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))) {
            JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[0], 'getservbyport', 1, 'port');

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        return JitNetworkServices::getservbyport($context, $args[0], $args[1]);
    }
}
