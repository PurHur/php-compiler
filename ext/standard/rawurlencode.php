<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringUrlencode;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** rawurlencode() for strings (subset of PHP; JIT/AOT via __string__rawurlencode). */
final class rawurlencode extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('rawurlencode() requires exactly one argument');
        }
        // Soft-null — coerce+deprecate on forward profile (#21188, ext/standard/url.c)
        $subject = VmString::trimFamilyStringArgForFrame(
            $frame,
            0,
            'rawurlencode',
            0,
            'string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::rawurlencode($subject));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('rawurlencode() requires exactly one argument');
        }

        // Null → soft-coerce to "" without helper IR (rawurlencode("") === ""; #21188).
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'rawurlencode', 0, 'string');

                return $context->getTypeFromString('__string__*')->constNull();
            }

            return JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'rawurlencode', 0, 'string');
        }

        $literal = $args[0]->compileTimeString ?? null;
        if (null !== $literal) {
            return $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load(
                    $context->constantStringFromString(VmString::rawurlencode($literal))
                )
            );
        }

        StringUrlencode::ensureLinked($context);
        $str = self::jitStringArg($context, $args[0]);

        return JitUrlencode::rawurlencode($context, $str);
    }

    /** Soft-null — coerce+deprecate on forward profile (#21188, ext/standard/url.c). */
    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'rawurlencode',
                0,
                'string'
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'rawurlencode',
            0,
            'string'
        );
    }
}
