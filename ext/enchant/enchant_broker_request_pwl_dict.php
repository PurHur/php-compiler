<?php

declare(strict_types=1);

namespace PHPCompiler\ext\enchant;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * enchant_broker_request_pwl_dict() — php-src ext/enchant/enchant.c (#20613).
 */
final class enchant_broker_request_pwl_dict extends Internal
{
    public function __construct()
    {
        parent::__construct('enchant_broker_request_pwl_dict');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'enchant_broker_request_pwl_dict() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $broker = VmEnchantArg::requireBroker($frame->calledArgs[0], 'enchant_broker_request_pwl_dict', 1);
        $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'enchant_broker_request_pwl_dict', 1, 'filename');
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('enchant_broker_request_pwl_dict() requires a VM context');
        }
        $result = VmEnchantCore::requestPwlDict($broker, $filename, $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($result->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('enchant_broker_request_pwl_dict() is not implemented for JIT in this compiler build (issue #20613)');
    }
}
