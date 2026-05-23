<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** crc32() — CRC32B checksum as signed int (PHP 8 single-arg subset; native LLVM in JIT/AOT). */
final class crc32 extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('crc32() requires exactly one argument in this compiler build');
        }
        $subject = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $subject->type) {
            throw new \LogicException('crc32() only supports strings in this compiler build');
        }
        $frame->returnVar->int(VmCrc32::compute($subject->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('crc32() requires exactly one argument in this compiler build');
        }

        return JitCrc32::compute($context, $this->jitString($context, $args[0], 'crc32() argument #1'));
    }
}
