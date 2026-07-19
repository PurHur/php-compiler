<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** numfmt_get_locale() — procedural NumberFormatter::getLocale (#20800). */
final class numfmt_get_locale extends Internal
{
    public function __construct()
    {
        parent::__construct('numfmt_get_locale');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'numfmt_get_locale() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmNumberFormatter::isFormatterObject($receiver->toObject())) {
            throw new \TypeError('numfmt_get_locale(): Argument #1 ($formatter) must be of type NumberFormatter');
        }
        // Optional $type matches php-src stub; current OOP path returns stored locale (#20728).
        if ($argc >= 2) {
            VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'numfmt_get_locale', 1, 'type');
        }
        $result = VmNumberFormatter::getLocale($receiver->toObject());
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
        throw new \Error('numfmt_get_locale() is not implemented for JIT in this compiler build (issue #20800)');
    }
}
