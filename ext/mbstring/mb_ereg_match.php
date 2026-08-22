<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_ereg_match() — match at start of string (php-src ext/mbstring/php_mbregex.c; #20024, #33655).
 *
 * JIT/AOT: catchable argc/TypeError (#33655); literal fold via {@see JitMbEregSearch::tryEregMatchFold};
 * runtime via {@see JitMbEreg} → {@see MbEregJitHelper} (#33811).
 */
final class mb_ereg_match extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_ereg_match');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'mb_ereg_match() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR — caller strict_types → TypeError on null (#33655; peer mb_ereg).
        $pattern = VmString::zparamStrBuiltinArgForFrame(
            $frame,
            0,
            'mb_ereg_match',
            0,
            'pattern'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $string = VmString::zparamStrBuiltinArgForFrame(
            $frame,
            1,
            'mb_ereg_match',
            1,
            'string'
        );
        $options = null;
        if (isset($frame->calledArgs[2])) {
            $options = VmString::coerceNullableStringBuiltinArg(
                $frame->calledArgs[2],
                'mb_ereg_match',
                2,
                'options'
            );
        }

        $matched = VmMbstring::eregMatchAnchored($pattern, $string, $options);
        if (!$matched && null !== VmMbstring::mbEregRegexCompileError(
            $pattern,
            VmMbstring::optionsImplyIgnoreCase($options)
        )) {
            VmMbstring::warnMbEregRegexFailure(
                $frame,
                'mb_ereg_match',
                $pattern,
                VmMbstring::optionsImplyIgnoreCase($options)
            );
        }

        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($matched): void {
            $ret->bool($matched);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                sprintf('mb_ereg_match() expects at least 2 arguments, %d given', $argc)
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_ereg_match_argc_cont');

            return self::foldFalse($context);
        }

        // Compile-time null string args under caller strict_types → TypeError (#33655).
        foreach ([[0, 'pattern'], [1, 'string']] as [$idx, $name]) {
            $isNull = JITVariable::TYPE_NULL === $args[$idx]->type || $args[$idx]->isNullConstant;
            if ($isNull && $context->callerStrictTypes) {
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    sprintf(
                        'mb_ereg_match(): Argument #%d ($%s) must be of type string, null given',
                        $idx + 1,
                        $name
                    )
                );
                BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_ereg_match_te_cont');

                return self::foldFalse($context);
            }
        }

        $folded = JitMbEregSearch::tryEregMatchFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        return JitMbEreg::invokeMatchAnchored($context, $args);
    }

    private static function foldFalse(Context $context): Value
    {
        // Boxed __value__ — matches mb_ereg / ExceptionBridge catchable paths (#33655).
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

        return JitValueBox::pointer($context, $slot);
    }
}
