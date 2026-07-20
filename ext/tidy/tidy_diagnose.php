<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** tidy_diagnose() — host bridge (php-src ext/tidy/tidy.c; #21500). */
final class tidy_diagnose extends Internal
{
    public function __construct()
    {
        parent::__construct('tidy_diagnose');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'tidy_diagnose', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmTidy::requireTidyObject($frame->calledArgs[0], 'tidy_diagnose', 0);
        $frame->returnVar->bool(VmTidy::diagnose($object, $frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('tidy_diagnose() is not implemented for JIT in this compiler build (issue #21500)');
    }
}
