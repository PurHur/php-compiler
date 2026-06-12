<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** gzpassthru() — output remaining gzip stream bytes to stdout (ext/zlib/zlib.c parity, #4657). */
final class gzpassthru extends Internal
{
    public function __construct()
    {
        parent::__construct('gzpassthru');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('gzpassthru() requires exactly one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $handle = VmStreamArg::requireStreamHandle($frame->calledArgs[0]->resolveIndirect(), 'gzpassthru');
        $result = VmGzStream::gzpassthru($handle);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('gzpassthru() is VM-only in this compiler build (issue #4657)');
    }
}
