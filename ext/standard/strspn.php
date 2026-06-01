<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * strspn() — length of initial segment matching a character mask (LLVM via libc).
 */
final class strspn extends Internal
{
    use SpnJitExtended;

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('strspn() requires two to four arguments in this compiler build');
        }
        $str = $frame->calledArgs[0]->resolveIndirect();
        $mask = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $str->type || Variable::TYPE_STRING !== $mask->type) {
            throw new \LogicException('strspn() requires two strings in this compiler build');
        }
        $offset = 0;
        if ($argc >= 3) {
            $offVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $offVar->type) {
                throw new \LogicException('strspn() offset must be an integer in this compiler build');
            }
            $offset = $offVar->toInt();
        }
        $length = null;
        if (4 === $argc) {
            $lenVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $lenVar->type) {
                throw new \LogicException('strspn() length must be an integer in this compiler build');
            }
            $length = $lenVar->toInt();
        }
        $frame->returnVar->int(
            VmString::strspn($str->toString(), $mask->toString(), $offset, $length)
        );
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('strspn() requires two to four arguments in this compiler build');
        }

        return $this->callSpnExtended($context, $args, true, 'strspn');
    }
}
