<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** bzwrite() — write to bzip2 stream (ext/bz2/bz2.c parity, #17301). */
final class bzwrite extends Internal
{
    public function __construct()
    {
        parent::__construct('bzwrite');
    }

    public function execute(Frame $frame): void
    {
        $fn = $this->getName();
        $this->requireArgCountRange($frame, $fn, 2, 3);
        $handle = VmStreamArg::requireStreamHandle($frame->calledArgs[0]->resolveIndirect(), $fn);
        if (null === $frame->returnVar) {
            return;
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $fn, 1, 'string');
        $length = null;
        if (3 === \count($frame->calledArgs)) {
            $length = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2]->resolveIndirect(),
                $fn,
                3,
                'length'
            );
        }
        $written = VmBz2Stream::bzwrite($handle, $data, $length);
        if (false === $written) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($written);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('bzwrite() JIT lowering not implemented — use VM path (#17301)');
    }
}
