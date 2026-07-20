<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * strspn() — length of initial segment matching a character mask (JIT via StrspnJitHelper PHP).
 *
 * PHP 8.4 (GH-12592): empty $characters returns 0; strcspn() returns full byte length instead.
 */
final class strspn extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('strspn() requires two to four arguments in this compiler build');
        }
        $str = VmString::trimFamilyStringArgForFrame($frame, 0, 'strspn', 0, 'string');
        $mask = VmString::zparamStrBuiltinArgForFrame($frame, 1, 'strspn', 1, 'characters');
        $offset = 0;
        if ($argc >= 3) {
            $offset = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'strspn', 3, 'offset');
        }
        $length = null;
        if (4 === $argc) {
            $length = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'strspn', 4, 'length');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(
            VmString::strspn($str, $mask, $offset, $length)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('strspn() requires two to four arguments in this compiler build');
        }
        return SpnJitLowering::extended($context, $args, true, 'strspn');
    }
}
