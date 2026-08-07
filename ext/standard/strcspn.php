<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * strcspn() — length of initial segment not matching a character mask (JIT via StrspnJitHelper PHP).
 *
 * PHP 8.4 (GH-12592): empty $characters returns the full byte length of the segment,
 * including bytes after an embedded NUL — see VmString::strcspn.
 */
final class strcspn extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.stub.php — ArgumentCountError (#28311).
        $this->requireArgCountRange($frame, 'strcspn', 2, 4);
        $argc = \count($frame->calledArgs);
        $str = VmString::trimFamilyStringArgForFrame($frame, 0, 'strcspn', 0, 'string');
        $mask = VmString::zparamStrBuiltinArgForFrame($frame, 1, 'strcspn', 1, 'characters');
        $offset = 0;
        if ($argc >= 3) {
            $offset = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'strcspn', 3, 'offset');
        }
        $length = null;
        if (4 === $argc) {
            $length = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'strcspn', 4, 'length');
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
        // Catchable ArgumentCountError (AOT) — peer strpos #21964 / #28311.
        if (!$this->requireArgCountRangeJit($context, $args, 'strcspn', 2, 4)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }

        return SpnJitLowering::extended($context, $args, false, 'strcspn');
    }
}
