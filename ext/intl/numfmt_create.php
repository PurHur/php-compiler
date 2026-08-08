<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** numfmt_create() — procedural NumberFormatter::create (#20754). */
final class numfmt_create extends Internal
{
    public function __construct()
    {
        parent::__construct('numfmt_create');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'numfmt_create() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        $locale = VmIntlDateFormatter::coerceLocaleArg($frame->calledArgs[0], 'numfmt_create', 0);
        $style = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'numfmt_create', 1, 'style');
        $pattern = $argc >= 3
            ? VmIntlDateFormatter::coerceOptionalPattern($frame->calledArgs[2], 'numfmt_create', 2)
            : null;
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmNumberFormatter::create($frame->vmContext, $locale, $style, $pattern);
        if (null === $object) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object($object);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitNumberFormatterCreate::invoke($context, ...$args);
    }
}

