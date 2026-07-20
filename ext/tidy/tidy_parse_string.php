<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** tidy_parse_string() — host bridge (php-src ext/tidy/tidy.c; #21464). */
final class tidy_parse_string extends Internal
{
    public function __construct()
    {
        parent::__construct('tidy_parse_string');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'tidy_parse_string', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext ?? throw new \LogicException('tidy_parse_string() requires VM context');
        $html = VmTidy::htmlStringArg($frame->calledArgs[0], 'tidy_parse_string', 0);
        $parsed = VmTidy::parseString($ctx, $html, $frame);
        if (false === $parsed) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($parsed);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('tidy_parse_string() is not implemented for JIT in this compiler build (issue #21464)');
    }
}
