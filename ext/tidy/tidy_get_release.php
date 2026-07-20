<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** tidy_get_release() — host bridge (php-src ext/tidy/tidy.c; #21542). */
final class tidy_get_release extends Internal
{
    public function __construct()
    {
        parent::__construct('tidy_get_release');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'tidy_get_release', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmTidy::getRelease($frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('tidy_get_release() is not implemented for JIT in this compiler build (issue #21542)');
    }
}
