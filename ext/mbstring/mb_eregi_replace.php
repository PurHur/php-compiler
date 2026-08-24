<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_eregi_replace() — case-insensitive multibyte regex replace (php-src php_mbregex.c; #20024, #33656, #34389).
 *
 * JIT/AOT: catchable argc/TypeError paths (#33656 / peer #30311 / #33648); 3-arg compile-time
 * literal fold via {@see JitMbEregSearch::tryEregReplaceFold}; runtime via
 * {@see JitMbEreg::invokeReplace} (#34389).
 */
final class mb_eregi_replace extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_eregi_replace');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(sprintf(
                'mb_eregi_replace() expects at least 3 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR — caller strict_types → TypeError on null (#33656 / peer #30311);
        // non-strict: Deprecated + coerce to '' (soft-null, Zend 8.4 parity).
        $pattern = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_eregi_replace', 0, 'pattern');
        if (null === $frame->returnVar) {
            return;
        }
        $replacement = VmString::trimFamilyStringArgForFrame($frame, 1, 'mb_eregi_replace', 1, 'replacement');
        $string = VmString::trimFamilyStringArgForFrame($frame, 2, 'mb_eregi_replace', 2, 'string');
        $options = null;
        if (4 === $argc) {
            $options = VmString::trimFamilyStringArgForFrame($frame, 3, 'mb_eregi_replace', 3, 'options');
        }

        $result = VmMbstring::eregReplace($pattern, $replacement, $string, true, $options);
        if ((false === $result || null === $result)
            && null !== VmMbstring::mbEregRegexCompileError($pattern, true)) {
            VmMbstring::warnMbEregRegexFailure($frame, 'mb_eregi_replace', $pattern, true);
        }

        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (null === $result) {
                $ret->null();

                return;
            }
            if (false === $result) {
                $ret->bool(false);

                return;
            }
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 3 || $argc > 4) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                sprintf('mb_eregi_replace() expects at least 3 arguments, %d given', $argc)
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_eregi_replace_argc_cont');

            return self::foldFalse($context);
        }

        // Compile-time null string args under caller strict_types → TypeError (#33656).
        foreach ([
            [0, 'pattern'],
            [1, 'replacement'],
            [2, 'string'],
        ] as [$idx, $name]) {
            $isNull = JITVariable::TYPE_NULL === $args[$idx]->type || $args[$idx]->isNullConstant;
            if ($isNull && $context->callerStrictTypes) {
                JitInternalStrictArg::rejectNullString($context, $args[$idx], 'mb_eregi_replace', $name, $idx + 1);

                return self::foldFalse($context);
            }
        }

        $folded = JitMbEregSearch::tryEregReplaceFold($context, $args, true);
        if (null !== $folded) {
            return $folded;
        }

        return JitMbEreg::invokeReplace($context, $args, true);
    }

    private static function foldFalse(Context $context): Value
    {
        // Boxed __value__ — matches mb_ereg_replace / ExceptionBridge catchable paths (#33656).
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

        return JitValueBox::pointer($context, $slot);
    }
}
