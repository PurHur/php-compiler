<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** tidy_config_count() — host bridge (php-src ext/tidy/tidy.c; #21541). */
final class tidy_config_count extends Internal
{
    public function __construct()
    {
        parent::__construct('tidy_config_count');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'tidy_config_count', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmTidy::requireTidyObject($frame->calledArgs[0], 'tidy_config_count', 0);
        $frame->returnVar->int(VmTidy::countKind($object, 'config', $frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('tidy_config_count() is not implemented for JIT in this compiler build (issue #21541)');
    }
}
