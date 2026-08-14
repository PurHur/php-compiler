<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * msgfmt_parse_message() — one-shot MessageFormat parse
 * (php-src msgfmt_format.c / msgfmt.stub.php; #20802).
 */
final class msgfmt_parse_message extends Internal
{
    public function __construct()
    {
        parent::__construct('msgfmt_parse_message');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'msgfmt_parse_message() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $locale = VmMessageFormatter::coerceLocaleArgFromFrame($frame, 0, 'msgfmt_parse_message', 0);
        $pattern = VmMessageFormatter::coercePatternArgFromFrame($frame, 1, 'msgfmt_parse_message', 1);
        $source = VmMessageFormatter::coerceSourceArgFromFrame($frame, 2, 'msgfmt_parse_message', 2);
        $result = VmMessageFormatter::parseMessage($locale, $pattern, $source);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmMessageFormatter::valuesToHashTable($result));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('msgfmt_parse_message() is not implemented for JIT in this compiler build (issue #20802)');
    }
}
