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
 * getservbyname() — service port by name+protocol (JIT/AOT via libc, issue #4024, #30281).
 *
 * Z_PARAM_STR: strict_types → TypeError on null; soft path DEP+coerce (#30281).
 * Excess/missing argc → Zend ArgumentCountError (#30567).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/network.c PHP_FUNCTION(getservbyname)
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.stub.php string $service, string $protocol
 */
final class getservbyname extends Internal
{
    public function __construct()
    {
        parent::__construct('getservbyname');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 2 (#30567; ext/standard/network.c).
        $this->requireExactArgCount($frame, 'getservbyname', 2);
        if (null === $frame->returnVar) {
            return;
        }
        // Z_PARAM_STR — caller strict_types → TypeError on null; else soft-null (#30281).
        $service = VmString::stringBuiltinArgForFrame($frame, 0, 'getservbyname', 0, 'service', false);
        $protocol = VmString::stringBuiltinArgForFrame($frame, 1, 'getservbyname', 1, 'protocol', false);
        $port = VmNetworkServices::getservbyname($service, $protocol);
        if (false === $port) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($port);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30567).
        if (!$this->requireExactJitArgCount($context, $args, 'getservbyname', 2)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }

        // Soft-null outside strict_types; strict → TypeError (#30281).
        // Early return after compile-time null TypeError — no libc call after abort
        // (AOT module verify: terminator mid-block; peer checkdnsrr #30261 / getmxrr #29810).
        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))) {
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'getservbyname', 0, 'service', 'string', null, false);

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }
        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'getservbyname', 1, 'protocol', 'string', null, false);

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        return JitNetworkServices::getservbyname($context, $args[0], $args[1]);
    }
}
