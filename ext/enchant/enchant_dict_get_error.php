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
 * enchant_dict_get_error() — php-src ext/enchant/enchant.c (#20613).
 */
final class enchant_dict_get_error extends Internal
{
    public function __construct()
    {
        parent::__construct('enchant_dict_get_error');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'enchant_dict_get_error() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $dict = VmEnchantArg::requireDictionary($frame->calledArgs[0], 'enchant_dict_get_error', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $msg = VmEnchantCore::dictGetError($dict);
        if (false === $msg) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($msg);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('enchant_dict_get_error() is not implemented for JIT in this compiler build (issue #20613)');
    }
}
