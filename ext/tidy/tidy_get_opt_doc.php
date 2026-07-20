<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** tidy_get_opt_doc() — host bridge (php-src ext/tidy/tidy.c; #21604). */
final class tidy_get_opt_doc extends Internal
{
    public function __construct()
    {
        parent::__construct('tidy_get_opt_doc');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'tidy_get_opt_doc', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmTidy::requireTidyObject($frame->calledArgs[0], 'tidy_get_opt_doc', 0);
        $option = VmTidy::htmlStringArg($frame->calledArgs[1], 'tidy_get_opt_doc', 1);
        $doc = VmTidy::getOptDoc($object, $option, $frame, false);
        if (null === $doc || false === $doc) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($doc);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('tidy_get_opt_doc() is not implemented for JIT in this compiler build (issue #21604)');
    }
}
