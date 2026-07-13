<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringStripslashes;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * stripslashes() — unescape addslashes bytes (subset of PHP).
 *
 * VM: {@see VmString::stripslashes()}; JIT/AOT: {@see StringStripslashes} + {@see StripslashesJitHelper}.
 */
final class stripslashes extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('stripslashes() requires exactly one argument in this compiler build');
        }
        $subject = InternalStrictArg::resolveCoercibleStringArg(
            $frame,
            0,
            'stripslashes',
            'string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::stripslashes($subject));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('stripslashes() requires exactly one argument in this compiler build');
        }

        StringStripslashes::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__string__stripslashes'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'stripslashes', 0, 'string')
        );
    }
}
