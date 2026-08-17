<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** strrchr() for two strings (VM: VmString; JIT/AOT: StrrchrJitHelper PHP #15406). */
final class strrchr extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.c — ArgumentCountError (#30703).
        $this->requireExactArgCount($frame, 'strrchr', 2);
        // Z_PARAM_STR — caller strict_types → TypeError on null; else soft-null (#29783 / #29766).
        $haystackStr = VmString::trimFamilyStringArgForFrame($frame, 0, 'strrchr', 0, 'haystack');
        $needleStr = VmString::stringBuiltinArgForFrame($frame, 1, 'strrchr', 1, 'needle');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmString::strrchr($haystackStr, $needleStr);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT/JIT) — #30703.
        if (!$this->requireExactJitArgCount($context, $args, 'strrchr', 2)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }

        // Soft-null outside strict_types — Zend 8.4 deprecate+coerce (#21444); strict → TypeError (#29783).
        $hay = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'strrchr', 0, 'haystack')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'strrchr', 0, 'haystack');
        $needle = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'strrchr', 1, 'needle')
            : JitStringBuiltinArg::lower($context, $args[1], 'strrchr', 1, 'needle');

        return JitStrrchr::find($context, $hay, $needle);
    }
}
