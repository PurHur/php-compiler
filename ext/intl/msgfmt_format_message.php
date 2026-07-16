<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * msgfmt_format_message() — one-shot MessageFormat (php-src msgformat.c; #6366).
 */
final class msgfmt_format_message extends Internal
{
    public function __construct()
    {
        parent::__construct('msgfmt_format_message');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'msgfmt_format_message() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $locale = VmMessageFormatter::coerceLocaleArg($frame->calledArgs[0], 'msgfmt_format_message', 0);
        $pattern = VmMessageFormatter::coercePatternArg($frame->calledArgs[1], 'msgfmt_format_message', 1);
        $args = VmMessageFormatter::coerceArgsArray($frame->calledArgs[2], 'msgfmt_format_message', 2);
        $result = VmMessageFormatter::formatMessage($locale, $pattern, $args);
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
        throw new \Error('msgfmt_format_message() is not implemented for JIT in this compiler build (issue #6366)');
    }
}
