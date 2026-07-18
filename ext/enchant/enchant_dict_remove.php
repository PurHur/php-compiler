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
 * enchant_dict_remove() — php-src ext/enchant/enchant.c (#20613).
 */
final class enchant_dict_remove extends Internal
{
    public function __construct()
    {
        parent::__construct('enchant_dict_remove');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'enchant_dict_remove() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $dict = VmEnchantArg::requireDictionary($frame->calledArgs[0], 'enchant_dict_remove', 1);
        $word = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'enchant_dict_remove', 1, 'word');
        VmEnchantCore::dictRemove($dict, $word);
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('enchant_dict_remove() is not implemented for JIT in this compiler build (issue #20613)');
    }
}
