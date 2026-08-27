<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\JitPregReplaceCallback;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\PregReplaceCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for mb_ereg_replace_callback() via ERE→PCRE + preg callback bridge (#35335).
 *
 * Compile-time ERE pattern + compile-time string user-function name only (peer #1177).
 * php-src: ext/mbstring/php_mbregex.c PHP_FUNCTION(mb_ereg_replace_callback)
 */
final class JitMbEregReplaceCallback
{
    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 3 || $argc > 4) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                sprintf(
                    'mb_ereg_replace_callback() expects at least 3 arguments, %d given',
                    $argc
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_ereg_replace_callback_argc_cont');

            return self::foldFalse($context);
        }

        if (!PregReplaceCallbackPolicy::isJitLowerable($args[1])) {
            throw new \LogicException(PregReplaceCallbackPolicy::jitRejectionMessage());
        }

        $patternCt = $args[0]->compileTimeString ?? null;
        if (null === $patternCt) {
            throw new \LogicException(
                'mb_ereg_replace_callback() JIT/AOT requires compile-time ERE pattern string in this compiler build (#35335)'
            );
        }

        $optionsCt = null;
        if ($argc >= 4 && JITVariable::TYPE_NULL !== $args[3]->type && !$args[3]->isNullConstant) {
            $optionsCt = $args[3]->compileTimeString ?? null;
        }

        $pcre = VmMbstring::mbEregRegex(
            $patternCt,
            VmMbstring::optionsImplyIgnoreCase($optionsCt),
            $optionsCt
        );
        if (null === $pcre) {
            return self::foldFalse($context);
        }

        $pattern = $context->builder->load($context->constantStringFromString($pcre));
        $string = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[2],
            'mb_ereg_replace_callback',
            2,
            'string'
        );

        return JitPregReplaceCallback::invoke($context, $pattern, $args[1], $string);
    }

    private static function foldFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

        return JitValueBox::pointer($context, $slot);
    }
}
