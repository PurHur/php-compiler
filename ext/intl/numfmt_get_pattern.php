<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** numfmt_get_pattern() — procedural NumberFormatter::getPattern (#20800). */
final class numfmt_get_pattern extends Internal
{
    public function __construct()
    {
        parent::__construct('numfmt_get_pattern');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'numfmt_get_pattern() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \TypeError('numfmt_get_pattern(): Argument #1 ($formatter) must be of type NumberFormatter');
        }
        $result = VmNumberFormatter::getPattern($receiver->toObject());
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
        throw new \Error('numfmt_get_pattern() is not implemented for JIT in this compiler build (issue #20800)');
    }
}
