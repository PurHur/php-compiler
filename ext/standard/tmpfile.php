<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** tmpfile() — anonymous temp FILE* stream (ext/standard/streams.c; issue #3228). */
final class tmpfile extends Internal
{
    public function __construct()
    {
        parent::__construct('tmpfile');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \LogicException('tmpfile() takes no arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $handle = VmFs::tmpfile();
        if (false === $handle) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->streamHandle($handle);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (0 !== \count($args)) {
            throw new \LogicException('tmpfile() takes no arguments in this compiler build');
        }

        return JitTmpfile::invoke($context);
    }
}
