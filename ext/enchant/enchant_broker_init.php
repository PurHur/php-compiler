<?php

declare(strict_types=1);

namespace PHPCompiler\ext\enchant;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * enchant_broker_init() — EnchantBroker object (php-src ext/enchant/enchant.c; #6230).
 */
final class enchant_broker_init extends Internal
{
    public function __construct()
    {
        parent::__construct('enchant_broker_init');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'enchant_broker_init() expects exactly 0 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('enchant_broker_init() requires a VM context');
        }
        $result = VmEnchantCore::brokerInit($ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($result->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('enchant_broker_init() is not implemented for JIT in this compiler build (issue #6230)');
    }
}
