<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** tidy_warning_count() — host bridge (php-src ext/tidy/tidy.c; #21541). */
final class tidy_warning_count extends Internal
{
    public function __construct()
    {
        parent::__construct('tidy_warning_count');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'tidy_warning_count', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmTidy::requireTidyObject($frame->calledArgs[0], 'tidy_warning_count', 0);
        $frame->returnVar->int(VmTidy::countKind($object, 'warning', $frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('tidy_warning_count() is not implemented for JIT in this compiler build (issue #21541)');
    }
}
