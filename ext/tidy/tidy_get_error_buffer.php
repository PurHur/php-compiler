<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** tidy_get_error_buffer() — host bridge (php-src ext/tidy/tidy.c; #21500). */
final class tidy_get_error_buffer extends Internal
{
    public function __construct()
    {
        parent::__construct('tidy_get_error_buffer');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'tidy_get_error_buffer', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmTidy::requireTidyObject($frame->calledArgs[0], 'tidy_get_error_buffer', 0);
        $buf = VmTidy::getErrorBuffer($object, $frame);
        if (false === $buf) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($buf);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('tidy_get_error_buffer() is not implemented for JIT in this compiler build (issue #21500)');
    }
}
