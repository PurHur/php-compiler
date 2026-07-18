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
 * enchant_dict_suggest() (php-src ext/enchant/enchant.c; #6230).
 */
final class enchant_dict_suggest extends Internal
{
    public function __construct()
    {
        parent::__construct('enchant_dict_suggest');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'enchant_dict_suggest() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $dict = VmEnchantArg::requireDictionary($frame->calledArgs[0], 'enchant_dict_suggest', 1);
        $word = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'enchant_dict_suggest', 1, 'word');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmEnchantCore::dictSuggest($dict, $word));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('enchant_dict_suggest() is not implemented for JIT in this compiler build (issue #6230)');
    }
}
