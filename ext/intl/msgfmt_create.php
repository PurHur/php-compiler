<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * msgfmt_create() — procedural alias of MessageFormatter::create (php-src msgformat.c; #6366).
 */
final class msgfmt_create extends Internal
{
    public function __construct()
    {
        parent::__construct('msgfmt_create');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'msgfmt_create() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $locale = VmMessageFormatter::coerceLocaleArgFromFrame($frame, 0, 'msgfmt_create', 0);
        $pattern = VmMessageFormatter::coercePatternArgFromFrame($frame, 1, 'msgfmt_create', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmMessageFormatter::create($frame->vmContext, $locale, $pattern);
        if (null === $object) {
            // php-src msgfmt_create → null on ICU failure (#22577).
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object($object);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('msgfmt_create() is not implemented for JIT in this compiler build (issue #6366)');
    }
}
