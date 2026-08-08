<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\JIT\Builtin\NormalizerNormalizeRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for Normalizer::normalize() / normalizer_normalize() (#28654).
 *
 * Boxes {@see NormalizerNormalizeJitHelper::normalizeArgv} `__string__*` into `__value__*` string.
 * Static method — no receiver (peer Locale::canonicalize / #28656).
 *
 * php-src: ext/intl/normalizer/normalizer_normalize.c — zim_Normalizer_normalize
 */
final class JitNormalizerNormalize
{
    /**
     * @param list<JITVariable> $args normalizer_normalize($input, $form = FORM_C)
     */
    public static function invokeProcedural(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'normalizer_normalize() expects 1 or 2 arguments, %d given',
                $argc
            ));
        }

        return self::invokePair(
            $context,
            $args[0],
            $args[1] ?? null,
            'normalizer_normalize',
            0,
            2
        );
    }

    /**
     * @param list<JITVariable> $args Normalizer::normalize($input, $form = FORM_C) — static, no $this
     */
    public static function invokeMethod(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'Normalizer::normalize() expects 1 or 2 arguments, %d given',
                $argc
            ));
        }

        return self::invokePair(
            $context,
            $args[0],
            $args[1] ?? null,
            'Normalizer::normalize',
            0,
            2
        );
    }

    private static function invokePair(
        Context $context,
        JITVariable $inputArg,
        ?JITVariable $formArg,
        string $function,
        int $inputIndex,
        int $formPosition
    ): Value {
        $literal = $inputArg->compileTimeString ?? JitStringArg::compileTimeLiteral($inputArg);
        $formLit = self::compileTimeForm($formArg);
        if (null !== $literal && null !== $formLit) {
            $out = VmNormalizer::normalize($literal, $formLit);

            return $context->builder->load($context->constantStringFromString($out));
        }

        // Z_PARAM_STR — null TypeError on 8.4 forward (constants + boxed VALUE) (#21063).
        $input = JitStringBuiltinArg::lowerZparamStr($context, $inputArg, $function, $inputIndex, 'string');
        $zparamStrict = $context->callerStrictTypes
            || JitStringBuiltinArg::requiresZparamStrStrictNullOnForwardProfile();
        $nullConst = JITVariable::TYPE_NULL === $inputArg->type || ($inputArg->isNullConstant ?? false);
        if ($nullConst && $zparamStrict) {
            return $context->builder->load($context->constantStringFromString(''));
        }

        $form = self::formAsI64($context, $formArg, $function, $formPosition);
        $raw = NormalizerNormalizeRuntime::invoke($context, $input, $form);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $raw
        );

        return $ptr;
    }

    private static function compileTimeForm(?JITVariable $formArg): ?int
    {
        if (null === $formArg) {
            return VmNormalizer::FORM_C;
        }
        if (null !== $formArg->compileTimeLong) {
            $form = $formArg->compileTimeLong;
            if (!\in_array($form, VmNormalizer::validForms(), true)) {
                throw new \ValueError(\sprintf(
                    'Normalizer::normalize(): Argument #2 ($form) must be one of Normalizer::FORM_* constants'
                ));
            }

            return $form;
        }

        return null;
    }

    private static function formAsI64(
        Context $context,
        ?JITVariable $formArg,
        string $function,
        int $formPosition
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        if (null === $formArg) {
            return $i64->constInt(VmNormalizer::FORM_C, false);
        }
        if (null !== $formArg->compileTimeLong) {
            $form = $formArg->compileTimeLong;
            if (!\in_array($form, VmNormalizer::validForms(), true)) {
                throw new \ValueError(\sprintf(
                    '%s(): Argument #%d ($form) must be one of Normalizer::FORM_* constants',
                    $function,
                    $formPosition
                ));
            }

            return $i64->constInt($form, false);
        }
        if (JITVariable::TYPE_VALUE === $formArg->type) {
            $ptr = JitValueBox::valuePtrFromVariable($context, $formArg);
            $long = $context->builder->call($context->lookupFunction('__value__readLong'), $ptr);

            return JitNestedHelperCoerce::scalarToI64($context, $long, $i64);
        }

        return JitNestedHelperCoerce::scalarToI64(
            $context,
            $context->helper->loadValue($formArg),
            $context->getTypeFromString('int32')
        );
    }
}
