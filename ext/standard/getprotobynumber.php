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
 * getprotobynumber() — protocol name by number (VM host; JIT/AOT via libc, issue #3650, #30283).
 *
 * Z_PARAM_LONG: strict_types → TypeError on null; soft path DEP+coerce to 0 (#30283).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/network.c PHP_FUNCTION(getprotobynumber)
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.stub.php int $protocol
 */
final class getprotobynumber extends Internal
{
    public function __construct()
    {
        parent::__construct('getprotobynumber');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('getprotobynumber() requires exactly one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        // Z_PARAM_LONG — caller strict_types → TypeError on null; else soft-null → 0 (#30283).
        $number = VmMath::parseZParamLongBuiltinArgForFrame(
            $frame,
            0,
            'getprotobynumber',
            1,
            'protocol'
        );
        $name = VmNetworkServices::getprotobynumber($number);
        if (false === $name) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($name);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('getprotobynumber() requires exactly one argument in this compiler build');
        }

        // Soft-null outside strict_types; strict → TypeError (#30283).
        // Early return after compile-time null TypeError — no libc call after abort
        // (AOT module verify: terminator mid-block; peer getprotobyname #30282).
        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))) {
            JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[0], 'getprotobynumber', 1, 'protocol');

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        return JitNetworkServices::getprotobynumber($context, $args[0]);
    }
}
