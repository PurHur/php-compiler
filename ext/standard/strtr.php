<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** strtr() — two-string byte translation (subset of PHP; native LLVM in JIT/AOT). */
final class strtr extends Internal
{
    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \LogicException('strtr() requires exactly three arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $string = $frame->calledArgs[0]->resolveIndirect();
        $from = $frame->calledArgs[1]->resolveIndirect();
        $to = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_STRING !== $string->type
            || Variable::TYPE_STRING !== $from->type
            || Variable::TYPE_STRING !== $to->type) {
            throw new \LogicException('strtr() requires string arguments in this compiler build');
        }
        $frame->returnVar->string(VmString::strtr(
            $string->toString(),
            $from->toString(),
            $to->toString()
        ));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException('strtr() requires exactly three arguments in this compiler build');
        }

        return JitStrtr::translate(
            $context,
            $this->jitString($context, $args[0], 'strtr() argument #1'),
            $this->jitString($context, $args[1], 'strtr() argument #2'),
            $this->jitString($context, $args[2], 'strtr() argument #3')
        );
    }
}
