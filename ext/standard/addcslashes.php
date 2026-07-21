<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringCslashes;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** addcslashes() — C-style selective escaping (php-src ext/standard/string.c; issue #3356). */
final class addcslashes extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.c — ArgumentCountError (#21756).
        $this->requireExactArgCount($frame, 'addcslashes', 2);
        // php-src basic_functions.stub.php: addcslashes(string $string, string $characters)
        $subject = self::vmStringArg($frame, 0, 'string');
        $charlist = self::vmStringArg($frame, 1, 'characters');
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmString::addcslashes($subject, $charlist))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'addcslashes', 2)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $subjectLit = JitStringArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
        $charlistLit = JitStringArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null !== $subjectLit && null !== $charlistLit) {
            return $context->builder->load(
                $context->constantStringFromString(VmString::addcslashes($subjectLit, $charlistLit))
            );
        }

        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'addcslashes', 0, 'string');

                return $context->getTypeFromString('__string__*')->constNull();
            }

            return JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'addcslashes', 0, 'string');
        }

        StringCslashes::ensureLinked($context);
        $subject = self::jitStringArg($context, $args[0], 0, 'string');
        if (null !== $charlistLit) {
            return $context->builder->call(
                $context->lookupFunction('__compiler_addcslashes'),
                $subject,
                $context->builder->load($context->constantStringFromString($charlistLit))
            );
        }
        $charlist = self::jitStringArg($context, $args[1], 1, 'characters');

        return $context->builder->call(
            $context->lookupFunction('__compiler_addcslashes'),
            $subject,
            $charlist
        );
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'addcslashes', $paramName)->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'addcslashes',
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
                'addcslashes',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'addcslashes',
            $argIndex,
            $paramName
        );
    }
}
