<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringAddslashes;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * addslashes() — escape quotes, backslash, and NUL (subset of PHP).
 *
 * VM: {@see VmString::addslashes()}; JIT/AOT: {@see StringAddslashes} + {@see AddslashesJitHelper}.
 */
final class addslashes extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('addslashes() requires exactly one argument in this compiler build');
        }
        $subject = InternalStrictArg::resolveCoercibleStringArg($frame, 0, 'addslashes', 'string');
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmString::addslashes($subject))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('addslashes() requires exactly one argument in this compiler build');
        }
        StringAddslashes::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__string__addslashes'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'addslashes', 0, 'string')
        );
    }
}
