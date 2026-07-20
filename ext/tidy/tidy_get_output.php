<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** tidy_get_output() — document string (php-src ext/tidy/tidy.c; #21499). */
final class tidy_get_output extends Internal
{
    public function __construct()
    {
        parent::__construct('tidy_get_output');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'tidy_get_output', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmTidy::requireTidyObject($frame->calledArgs[0], 'tidy_get_output', 0);
        $frame->returnVar->string(VmTidy::getOutput($object, $frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('tidy_get_output() is not implemented for JIT in this compiler build (issue #21499)');
    }
}
