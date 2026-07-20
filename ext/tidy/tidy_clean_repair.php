<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** tidy_clean_repair() — procedural alias of tidy::cleanRepair (php-src ext/tidy/tidy.c; #21499). */
final class tidy_clean_repair extends Internal
{
    public function __construct()
    {
        parent::__construct('tidy_clean_repair');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'tidy_clean_repair', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmTidy::requireTidyObject($frame->calledArgs[0], 'tidy_clean_repair', 0);
        $ok = VmTidy::cleanRepair($object, $frame);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('tidy_clean_repair() is not implemented for JIT in this compiler build (issue #21499)');
    }
}
