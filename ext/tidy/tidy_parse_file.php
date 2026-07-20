<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** tidy_parse_file() — host bridge (php-src ext/tidy/tidy.c; #21501). */
final class tidy_parse_file extends Internal
{
    public function __construct()
    {
        parent::__construct('tidy_parse_file');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'tidy_parse_file', 1, 4);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext ?? throw new \LogicException('tidy_parse_file() requires VM context');
        $filename = VmTidy::htmlStringArg($frame->calledArgs[0], 'tidy_parse_file', 0);
        $parsed = VmTidy::parseFile($ctx, $filename, $frame);
        if (false === $parsed) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($parsed);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('tidy_parse_file() is not implemented for JIT in this compiler build (issue #21501)');
    }
}
