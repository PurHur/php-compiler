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
 * enchant_broker_set_ordering() — php-src ext/enchant/enchant.c (#20613).
 */
final class enchant_broker_set_ordering extends Internal
{
    public function __construct()
    {
        parent::__construct('enchant_broker_set_ordering');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'enchant_broker_set_ordering() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $broker = VmEnchantArg::requireBroker($frame->calledArgs[0], 'enchant_broker_set_ordering', 1);
        $tag = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'enchant_broker_set_ordering', 1, 'tag');
        $ordering = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'enchant_broker_set_ordering', 2, 'ordering');
        if ('' === $tag) {
            throw new \ValueError('enchant_broker_set_ordering(): Argument #2 ($tag) must not be empty');
        }
        if ('' === $ordering) {
            throw new \ValueError('enchant_broker_set_ordering(): Argument #3 ($ordering) must not be empty');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmEnchantCore::setOrdering($broker, $tag, $ordering));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('enchant_broker_set_ordering() is not implemented for JIT in this compiler build (issue #20613)');
    }
}
