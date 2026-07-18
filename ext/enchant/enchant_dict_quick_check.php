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
 * enchant_dict_quick_check() — php-src ext/enchant/enchant.c (#20613).
 */
final class enchant_dict_quick_check extends Internal
{
    public function __construct()
    {
        parent::__construct('enchant_dict_quick_check');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'enchant_dict_quick_check() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        $dict = VmEnchantArg::requireDictionary($frame->calledArgs[0], 'enchant_dict_quick_check', 1);
        $word = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'enchant_dict_quick_check', 1, 'word');
        $suggestionsArg = null;
        if ($argc >= 3) {
            $suggestionsArg = $frame->calledArgs[2]->resolveIndirect();
            $suggestionsArg->array(new \PHPCompiler\VM\HashTable());
        }
        $ok = VmEnchantCore::dictQuickCheck($dict, $word, $suggestionsArg);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('enchant_dict_quick_check() is not implemented for JIT in this compiler build (issue #20613)');
    }
}
