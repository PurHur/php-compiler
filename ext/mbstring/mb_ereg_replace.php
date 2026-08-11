<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_ereg_replace() — multibyte regex replace (php-src ext/mbstring/php_mbregex.c; #4635, #30311).
 */
final class mb_ereg_replace extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_ereg_replace');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(sprintf(
                'mb_ereg_replace() expects at least 3 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR — caller strict_types → TypeError on null (#30311);
        // non-strict: Deprecated + coerce to '' (soft-null, Zend 8.4 parity).
        $pattern = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_ereg_replace', 0, 'pattern');
        if (null === $frame->returnVar) {
            return;
        }
        $replacement = VmString::trimFamilyStringArgForFrame($frame, 1, 'mb_ereg_replace', 1, 'replacement');
        $string = VmString::trimFamilyStringArgForFrame($frame, 2, 'mb_ereg_replace', 2, 'string');
        if (4 === $argc) {
            VmString::trimFamilyStringArgForFrame($frame, 3, 'mb_ereg_replace', 3, 'options');
        }

        $result = VmMbstring::eregReplace($pattern, $replacement, $string, false);
        if ((false === $result || null === $result) && null !== VmMbstring::mbEregRegexCompileError($pattern, false)) {
            VmMbstring::warnMbEregRegexFailure($frame, 'mb_ereg_replace', $pattern, false);
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
            throw new \LogicException('mb_ereg_replace() requires three or four arguments');
        }

        // Compile-time null string args under caller strict_types → TypeError (#30311).
        foreach ([
            [0, 'pattern'],
            [1, 'replacement'],
            [2, 'string'],
        ] as [$idx, $name]) {
            $isNull = JITVariable::TYPE_NULL === $args[$idx]->type || $args[$idx]->isNullConstant;
            if ($isNull && $context->callerStrictTypes) {
                JitInternalStrictArg::rejectNullString($context, $args[$idx], 'mb_ereg_replace', $name, $idx + 1);

                return self::foldFalse($context);
            }
        }

        throw new \LogicException('mb_ereg_replace() is not lowered for JIT/AOT in this compiler build');
    }

    private static function foldFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

        return JitValueBox::pointer($context, $slot);
    }
}
