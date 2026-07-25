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
        // php-src ext/standard/string.c — ArgumentCountError (#23164).
        $this->requireExactArgCount($frame, 'addslashes', 1);
        $subject = self::vmStringArg($frame, 0, 'string');
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmString::addslashes($subject))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'addslashes', 1)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        $subject = self::jitStringArg($context, $args[0], 0, 'string');
        StringAddslashes::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__string__addslashes'),
            $subject
        );
    }

    /** Zend 8.4 DEP+coerces null (not TypeError until 9.0); use soft-null path (#21406). */
    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'addslashes', $paramName)->toString();
        }

        return VmString::trimFamilyStringArgForFrame(
            $frame,
            $argIndex,
            'addslashes',
            $argIndex,
            $paramName
        );
    }

    private static function jitStringArg(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): Value {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'addslashes',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'addslashes',
            $argIndex,
            $paramName
        );
    }
}
