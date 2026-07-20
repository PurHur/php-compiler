<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** tidy_get_config() — host bridge (php-src ext/tidy/tidy.c; #21540). */
final class tidy_get_config extends Internal
{
    public function __construct()
    {
        parent::__construct('tidy_get_config');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'tidy_get_config', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmTidy::requireTidyObject($frame->calledArgs[0], 'tidy_get_config', 0);
        $cfg = VmTidy::getConfig($object, $frame);
        if (null === $cfg) {
            VmTidy::assignConfigArray($frame->returnVar, []);

            return;
        }
        VmTidy::assignConfigArray($frame->returnVar, $cfg);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('tidy_get_config() is not implemented for JIT in this compiler build (issue #21540)');
    }
}
