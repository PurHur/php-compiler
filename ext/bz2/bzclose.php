<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** bzclose() — close bzip2 stream (ext/bz2/bz2.c parity, #17301). */
final class bzclose extends Internal
{
    public function __construct()
    {
        parent::__construct('bzclose');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'bzclose', 1);
        $handle = VmStreamArg::requireStreamHandle($frame->calledArgs[0]->resolveIndirect(), 'bzclose');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmBz2Stream::bzclose($handle));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('bzclose() JIT lowering not implemented — use VM path (#17301)');
    }
}
