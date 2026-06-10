<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** stream_get_wrappers() — list registered stream protocols (ext/standard/streams.c; #3329, #3383). */
final class stream_get_wrappers extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_get_wrappers');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \ArgumentCountError(
                'stream_get_wrappers() expects exactly 0 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmFs::stringListToArray(VmStreamWrapperRegistry::getWrappers()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('stream_get_wrappers() is not implemented for JIT in this compiler build (issue #3383)');
    }
}
