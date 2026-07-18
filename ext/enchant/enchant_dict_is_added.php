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
 * enchant_dict_is_added() — php-src ext/enchant/enchant.c (#20613).
 */
final class enchant_dict_is_added extends Internal
{
    public function __construct()
    {
        parent::__construct('enchant_dict_is_added');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'enchant_dict_is_added() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $dict = VmEnchantArg::requireDictionary($frame->calledArgs[0], 'enchant_dict_is_added', 1);
        $word = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'enchant_dict_is_added', 1, 'word');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmEnchantCore::dictIsAdded($dict, $word));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('enchant_dict_is_added() is not implemented for JIT in this compiler build (issue #20613)');
    }
}
