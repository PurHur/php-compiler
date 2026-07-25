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
        // php-src ext/standard/string.c — ArgumentCountError (#23164).
        $this->requireExactArgCount($frame, 'stripslashes', 1);
        $subject = self::vmStringArg($frame, 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::stripslashes($subject));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'stripslashes', 1)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        // Null → soft-coerce to "" without helper IR (stripslashes("") === ""; #21180 / #20007).
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'stripslashes', 0, 'string');

                return $context->getTypeFromString('__string__*')->constNull();
            }

            return JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'stripslashes', 0, 'string');
        }

        StringStripslashes::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__string__stripslashes'),
            self::jitStringArg($context, $args[0], 0, 'string')
        );
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'stripslashes', $paramName)->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'stripslashes',
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
                'stripslashes',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'stripslashes',
            $argIndex,
            $paramName
        );
    }
}
