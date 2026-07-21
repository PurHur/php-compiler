<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** tidy_repair_file() — host bridge (php-src ext/tidy/tidy.c; #21498). */
final class tidy_repair_file extends Internal
{
    public function __construct()
    {
        parent::__construct('tidy_repair_file');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'tidy_repair_file', 1, 4);
        if (null === $frame->returnVar) {
            return;
        }
        $filename = VmTidy::htmlStringArg($frame->calledArgs[0], 'tidy_repair_file', 0);
        $config = VmTidy::optionalConfigArg($frame->calledArgs, 1, 'tidy_repair_file');
        $encoding = VmTidy::optionalEncodingArg($frame->calledArgs, 2, 'tidy_repair_file');
        $useIncludePath = VmTidy::optionalUseIncludePathArg($frame->calledArgs, 3);
        $repaired = VmTidy::repairFile($filename, $config, $encoding, $useIncludePath, $frame);
        if (false === $repaired) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($repaired);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('tidy_repair_file() is not implemented for JIT in this compiler build (issue #21498)');
    }
}
