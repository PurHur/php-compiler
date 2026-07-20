<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** tidy_is_xhtml() — host bridge (php-src ext/tidy/tidy.c; #21542). */
final class tidy_is_xhtml extends Internal
{
    public function __construct()
    {
        parent::__construct('tidy_is_xhtml');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'tidy_is_xhtml', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmTidy::requireTidyObject($frame->calledArgs[0], 'tidy_is_xhtml', 0);
        $frame->returnVar->bool(VmTidy::isXhtml($object, $frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('tidy_is_xhtml() is not implemented for JIT in this compiler build (issue #21542)');
    }
}
