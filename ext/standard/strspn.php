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
        // php-src ext/standard/string.stub.php — ArgumentCountError (#28311).
        $this->requireArgCountRange($frame, 'strspn', 2, 4);
        $argc = \count($frame->calledArgs);
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
        // Catchable ArgumentCountError (AOT) — peer strpos #21964 / #28311.
        if (!$this->requireArgCountRangeJit($context, $args, 'strspn', 2, 4)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }

        return SpnJitLowering::extended($context, $args, true, 'strspn');
    }
}
