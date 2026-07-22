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
 * enchant_dict_add() — php-src ext/enchant/enchant.c (#20613).
 *
 * Also registered as deprecated alias enchant_dict_add_to_personal (#22270;
 * php-src enchant.stub.php @alias enchant_dict_add).
 */
final class enchant_dict_add extends Internal
{
    public function __construct(string $name = 'enchant_dict_add')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $fn = $this->getName();
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects exactly 2 arguments, %d given',
                $fn,
                $argc
            ));
        }
        $dict = VmEnchantArg::requireDictionary($frame->calledArgs[0], $fn, 1);
        $word = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $fn, 1, 'word');
        VmEnchantCore::dictAdd($dict, $word);
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            $this->getName().'() is not implemented for JIT in this compiler build (issue #20613)'
        );
    }
}
