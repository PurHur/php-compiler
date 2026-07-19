<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** numfmt_format_currency() — procedural NumberFormatter::formatCurrency (#20754). */
final class numfmt_format_currency extends Internal
{
    public function __construct()
    {
        parent::__construct('numfmt_format_currency');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'numfmt_format_currency() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \TypeError('numfmt_format_currency(): Argument #1 ($formatter) must be of type NumberFormatter');
        }
        $num = VmNumberFormatter::coerceFloatArg($frame->calledArgs[1], 'numfmt_format_currency', 1, 'num');
        $currency = VmNumberFormatter::coerceStringArg($frame->calledArgs[2], 'numfmt_format_currency', 2, 'currency');
        $result = VmNumberFormatter::formatCurrency($receiver->toObject(), $num, $currency);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('numfmt_format_currency() is not implemented for JIT in this compiler build (issue #20754)');
    }
}
