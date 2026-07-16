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

/** urlencode() for strings (subset of PHP; JIT/AOT via __string__urlencode). */
final class urlencode extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('urlencode() requires exactly one argument');
        }
        // Z_PARAM_STR — null TypeError on 8.4 forward profile (#19272, ext/standard/url.c)
        $subject = VmString::zparamStrBuiltinArgForFrame(
            $frame,
            0,
            'urlencode',
            0,
            'string'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::urlencode($subject));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('urlencode() requires exactly one argument');
        }

        if (
            (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))
            && ($context->callerStrictTypes || JitStringBuiltinArg::requiresZparamStrStrictNullOnForwardProfile())
        ) {
            return JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'urlencode', 0, 'string');
        }

        $literal = $args[0]->compileTimeString ?? null;
        if (
            null === $literal
            && (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))
        ) {
            $literal = '';
        }
        if (null !== $literal) {
            return $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load(
                    $context->constantStringFromString(VmString::urlencode($literal))
                )
            );
        }

        StringUrlencode::ensureLinked($context);
        $str = self::jitStringArg($context, $args[0]);

        return JitUrlencode::urlencode($context, $str);
    }

    /** Z_PARAM_STR — null TypeError on 8.4 forward profile (#19272, ext/standard/url.c). */
    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'urlencode',
                0,
                'string'
            );
        }

        return JitStringBuiltinArg::lowerZparamStr(
            $context,
            $arg,
            'urlencode',
            0,
            'string'
        );
    }
}
