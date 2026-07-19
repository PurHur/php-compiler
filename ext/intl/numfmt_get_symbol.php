<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** numfmt_get_symbol() — procedural NumberFormatter::getSymbol (#20812). */
final class numfmt_get_symbol extends Internal
{
    public function __construct()
    {
        parent::__construct('numfmt_get_symbol');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'numfmt_get_symbol() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \TypeError('numfmt_get_symbol(): Argument #1 ($formatter) must be of type NumberFormatter');
        }
        $symbol = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'numfmt_get_symbol', 1, 'symbol');
        $result = VmNumberFormatter::getSymbol($receiver->toObject(), $symbol);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string((string) $result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('numfmt_get_symbol() is not implemented for JIT in this compiler build (issue #20812)');
    }
}
