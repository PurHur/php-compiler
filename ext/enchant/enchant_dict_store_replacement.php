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
 * enchant_dict_store_replacement() — php-src ext/enchant/enchant.c (#20613).
 */
final class enchant_dict_store_replacement extends Internal
{
    public function __construct()
    {
        parent::__construct('enchant_dict_store_replacement');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'enchant_dict_store_replacement() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $dict = VmEnchantArg::requireDictionary($frame->calledArgs[0], 'enchant_dict_store_replacement', 1);
        $mis = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'enchant_dict_store_replacement', 1, 'misspelled');
        $cor = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'enchant_dict_store_replacement', 2, 'correct');
        VmEnchantCore::dictStoreReplacement($dict, $mis, $cor);
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('enchant_dict_store_replacement() is not implemented for JIT in this compiler build (issue #20613)');
    }
}
