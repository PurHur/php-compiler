<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** crc32c() — CRC32C (Castagnoli), signed 32-bit int (VM + JIT/AOT via __compiler_crc32c). */
final class crc32c extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('crc32c() requires exactly one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $subject = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $subject->type) {
            throw new \LogicException('crc32c() only supports strings in this compiler build');
        }
        $frame->returnVar->int(VmCrc32c::compute($subject->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('crc32c() requires exactly one argument in this compiler build');
        }
        $subject = $this->jitString($context, $args[0], 'crc32c() argument #1');

        return JitCrc32c::compute($context, $subject);
    }
}
