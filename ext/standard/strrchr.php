<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
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
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        // Null → TypeError under strict without helper IR after abort (peer utf8_encode #29889 / #29783).
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'strrchr', 0, 'haystack');

                return $context->getTypeFromString('__string__*')->constNull();
            }
            $hay = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'strrchr', 0, 'haystack');
        } else {
            $hay = $context->callerStrictTypes
                ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'strrchr', 0, 'haystack')
                : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'strrchr', 0, 'haystack');
        }

        if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'strrchr', 1, 'needle');

                return $context->getTypeFromString('__string__*')->constNull();
            }
            $needle = JitStringBuiltinArg::lower($context, $args[1], 'strrchr', 1, 'needle');
        } else {
            $needle = $context->callerStrictTypes
                ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'strrchr', 1, 'needle')
                : JitStringBuiltinArg::lower($context, $args[1], 'strrchr', 1, 'needle');
        }

        return JitStrrchr::find($context, $hay, $needle);
    }
}
