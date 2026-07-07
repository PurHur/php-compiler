<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** bzread() — read from bzip2 stream (ext/bz2/bz2.c parity, #17301). */
final class bzread extends Internal
{
    public function __construct()
    {
        parent::__construct('bzread');
    }

    public function execute(Frame $frame): void
    {
        $fn = $this->getName();
        $this->requireArgCountRange($frame, $fn, 1, 2);
        $handle = VmStreamArg::requireStreamHandle($frame->calledArgs[0]->resolveIndirect(), $fn);
        if (null === $frame->returnVar) {
            return;
        }
        $length = 4096;
        if (2 === \count($frame->calledArgs)) {
            $length = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1]->resolveIndirect(),
                $fn,
                2,
                'length'
            );
        }
        $data = VmBz2Stream::bzread($handle, $length);
        if (false === $data) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($data);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('bzread() JIT lowering not implemented — use VM path (#17301)');
    }
}
