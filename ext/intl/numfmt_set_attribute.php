<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** numfmt_set_attribute() — procedural NumberFormatter::setAttribute (#20800). */
final class numfmt_set_attribute extends Internal
{
    public function __construct()
    {
        parent::__construct('numfmt_set_attribute');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'numfmt_set_attribute() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \TypeError('numfmt_set_attribute(): Argument #1 ($formatter) must be of type NumberFormatter');
        }
        $attr = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'numfmt_set_attribute', 1, 'attribute');
        $value = VmNumberFormatter::coerceFloatArg($frame->calledArgs[2], 'numfmt_set_attribute', 2, 'value');
        $ok = VmNumberFormatter::setAttribute($receiver->toObject(), $attr, $value);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('numfmt_set_attribute() is not implemented for JIT in this compiler build (issue #20800)');
    }
}
