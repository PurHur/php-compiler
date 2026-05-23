<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** strrchr() for two strings (subset of PHP; LLVM via libc strrchr + slice). */
final class strrchr extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('strrchr() requires exactly two arguments in this compiler build');
        }
        $haystack = $frame->calledArgs[0]->resolveIndirect();
        $needle = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $haystack->type || Variable::TYPE_STRING !== $needle->type) {
            throw new \LogicException('strrchr() only supports strings in this compiler build');
        }
        $result = VmString::strrchr($haystack->toString(), $needle->toString());
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('strrchr() requires exactly two arguments in this compiler build');
        }

        return JitStrrchr::find(
            $context,
            $this->jitString($context, $args[0], 'strrchr() argument #1'),
            $this->jitString($context, $args[1], 'strrchr() argument #2')
        );
    }
}
