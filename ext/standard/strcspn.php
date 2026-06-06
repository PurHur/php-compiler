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
 * strcspn() — length of initial segment not matching a character mask (LLVM via JitStrspn).
 *
 * PHP 8.4 (GH-12592): empty $characters returns the full byte length of the segment,
 * including bytes after an embedded NUL — see VmString::strcspn.
 */
final class strcspn extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('strcspn() requires two to four arguments in this compiler build');
        }
        $str = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'strcspn', 0, 'string');
        $mask = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'strcspn', 1, 'characters');
        $offset = 0;
        if ($argc >= 3) {
            $offVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $offVar->type) {
                throw new \LogicException('strcspn() offset must be an integer in this compiler build');
            }
            $offset = $offVar->toInt();
        }
        $length = null;
        if (4 === $argc) {
            $lenVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $lenVar->type) {
                throw new \LogicException('strcspn() length must be an integer in this compiler build');
            }
            $length = $lenVar->toInt();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(
            VmString::strcspn($str, $mask, $offset, $length)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('strcspn() requires two to four arguments in this compiler build');
        }

        return JitStrspn::extended($context, $args, false, 'strcspn');
    }
}
