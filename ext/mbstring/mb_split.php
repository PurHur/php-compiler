<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_split() — multibyte regex split (php-src ext/mbstring/php_mbregex.c; #13367, #29811, #31312).
 */
final class mb_split extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_split');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'mb_split() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR $pattern / $string — caller strict_types → TypeError on null (#29811);
        // non-strict: Deprecated + coerce to '' (soft-null, Zend 8.4 parity #30069).
        $pattern = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_split', 0, 'pattern');
        if (null === $frame->returnVar) {
            return;
        }
        $string = VmString::trimFamilyStringArgForFrame($frame, 1, 'mb_split', 1, 'string');
        // Z_PARAM_LONG $limit — soft-null DEP+coerce outside strict_types (#31312 / php_mbregex.c).
        $limit = -1;
        if ($argc >= 3) {
            $limit = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                2,
                'mb_split',
                3,
                'limit'
            );
        }

        $result = VmMbstring::split($pattern, $string, $limit);
        if (false === $result) {
            if (null !== VmMbstring::mbSplitRegexCompileError($pattern)) {
                VmMbstring::warnMbSplitRegexFailure($frame, $pattern);
            }
            BuiltinExecute::writeReturn($frame, static fn (Variable $ret) => $ret->bool(false));

            return;
        }

        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->array(MbstringState::hashTableFromStringList($result));
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('mb_split() requires two or three arguments');
        }

        // Compile-time null $pattern/$string under caller strict_types → TypeError (#29811).
        $patternIsNull = JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant;
        if ($patternIsNull && $context->callerStrictTypes) {
            JitInternalStrictArg::rejectNullString($context, $args[0], 'mb_split', 'pattern', 1);

            return self::foldFalse($context);
        }
        $stringIsNull = JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant;
        if ($stringIsNull && $context->callerStrictTypes) {
            JitInternalStrictArg::rejectNullString($context, $args[1], 'mb_split', 'string', 2);

            return self::foldFalse($context);
        }
        // Z_PARAM_LONG $limit — null under strict_types → TypeError (#31312).
        $limitIsNull = false;
        if ($argc >= 3) {
            $limitIsNull = JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant;
            if ($limitIsNull && $context->callerStrictTypes) {
                JitInternalStrictArg::rejectNullInt($context, $args[2], 'mb_split', 'limit', 3);

                return self::foldFalse($context);
            }
        }

        $patternLit = $args[0]->compileTimeString ?? null;
        $stringLit = $args[1]->compileTimeString ?? null;
        if (
            JITVariable::TYPE_STRING === $args[0]->type
            && null !== $patternLit
            && JITVariable::TYPE_STRING === $args[1]->type
            && null !== $stringLit
        ) {
            $limit = -1;
            if ($argc >= 3) {
                if ($limitIsNull) {
                    // Soft-null DEP+coerce to 0 (#31312) — php-src Z_PARAM_LONG.
                    JitIntdiv::emitNullIntDeprecation($context, 'mb_split', 3, 'limit');
                    $limit = 0;
                } else {
                    $resolved = self::compileTimeLong($context, $args[2]);
                    if (null === $resolved) {
                        throw new \LogicException(
                            'mb_split() JIT requires a compile-time int $limit in this compiler build'
                        );
                    }
                    $limit = $resolved;
                }
            }
            $result = VmMbstring::split($patternLit, $stringLit, $limit);
            if (false === $result) {
                return self::foldFalse($context);
            }

            return self::foldStringList($context, $result);
        }

        throw new \LogicException('mb_split() is not lowered for JIT/AOT in this compiler build');
    }

    /** @param list<string> $parts */
    private static function foldStringList(Context $context, array $parts): Value
    {
        $ht = HashTableHelper::alloc($context);
        $setString = $context->lookupFunction('__hashtable__setStringAt');
        $sizeT = $context->getTypeFromString('size_t');
        foreach ($parts as $i => $part) {
            $slice = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load($context->constantStringFromString($part))
            );
            $context->builder->call(
                $setString,
                $ht,
                $sizeT->constInt($i, false),
                $slice
            );
        }

        return $ht;
    }

    private static function compileTimeLong(Context $context, JITVariable $var): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG === $var->type && JITVariable::KIND_VALUE === $var->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
                return (int) $lib->LLVMConstIntGetSExtValue($var->value->value);
            }
        }
        if (JITVariable::TYPE_INTEGER === $var->type && null !== ($var->compileTimeInteger ?? null)) {
            return $var->compileTimeInteger;
        }

        return null;
    }

    private static function foldFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

        return JitValueBox::pointer($context, $slot);
    }
}
