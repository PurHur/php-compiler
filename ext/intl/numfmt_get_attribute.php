<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** numfmt_get_attribute() — procedural NumberFormatter::getAttribute (#20800). */
final class numfmt_get_attribute extends Internal
{
    public function __construct()
    {
        parent::__construct('numfmt_get_attribute');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'numfmt_get_attribute() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \TypeError('numfmt_get_attribute(): Argument #1 ($formatter) must be of type NumberFormatter');
        }
        $attr = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'numfmt_get_attribute', 1, 'attribute');
        $result = VmNumberFormatter::getAttribute($receiver->toObject(), $attr);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        if (\is_float($result)) {
            $frame->returnVar->float($result);
        } else {
            $frame->returnVar->int((int) $result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('numfmt_get_attribute() is not implemented for JIT in this compiler build (issue #20800)');
    }
}
