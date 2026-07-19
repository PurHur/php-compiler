<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** numfmt_set_pattern() — procedural NumberFormatter::setPattern (#20800). */
final class numfmt_set_pattern extends Internal
{
    public function __construct()
    {
        parent::__construct('numfmt_set_pattern');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'numfmt_set_pattern() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \TypeError('numfmt_set_pattern(): Argument #1 ($formatter) must be of type NumberFormatter');
        }
        $pattern = VmNumberFormatter::coerceStringArg($frame->calledArgs[1], 'numfmt_set_pattern', 1, 'pattern');
        $ok = VmNumberFormatter::setPattern($receiver->toObject(), $pattern);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('numfmt_set_pattern() is not implemented for JIT in this compiler build (issue #20800)');
    }
}
