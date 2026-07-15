<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** strrchr() for two strings (VM: VmString; JIT/AOT: StrrchrJitHelper PHP #15406). */
final class strrchr extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('strrchr() requires exactly two arguments in this compiler build');
        }
        $haystackStr = VmString::coerceZparamStrBuiltinArg($frame->calledArgs[0], 'strrchr', 0, 'haystack');
        $needleStr = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'strrchr', 1, 'needle');
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
        if (2 !== \count($args)) {
            throw new \LogicException('strrchr() requires exactly two arguments in this compiler build');
        }

        // Early TypeError — skip StringStrrchr ensureLinked on null haystack (#19242).
        if (
            (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))
            && ($context->callerStrictTypes || JitStringBuiltinArg::requiresZparamStrStrictNullOnForwardProfile())
        ) {
            JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'strrchr', 0, 'haystack');

            return JitStrtok::deadFalseResult($context);
        }

        return JitStrrchr::find(
            $context,
            JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'strrchr', 0, 'haystack'),
            JitStringBuiltinArg::lower($context, $args[1], 'strrchr', 1, 'needle')
        );
    }
}
