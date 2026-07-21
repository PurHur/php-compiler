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
        $this->requireArgCountRange($frame, 'tidy_parse_string', 1, 3);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext ?? throw new \LogicException('tidy_parse_string() requires VM context');
        $html = VmTidy::htmlStringArg($frame->calledArgs[0], 'tidy_parse_string', 0);
        $config = VmTidy::optionalConfigArg($frame->calledArgs, 1, 'tidy_parse_string');
        $encoding = VmTidy::optionalEncodingArg($frame->calledArgs, 2, 'tidy_parse_string');
        $parsed = VmTidy::parseString($ctx, $html, $config, $encoding, $frame);
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
