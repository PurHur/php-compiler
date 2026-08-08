<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringTrimMask;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * ltrim() for strings (default whitespace or optional $characters mask; php-src string.c).
 */
final class string_ltrim extends Internal
{
    public function __construct()
    {
        parent::__construct('ltrim');
    }

    public function execute(Frame $frame): void
    {
        // php-src string.stub.php — arity ≤2; no $mode (#28230 / #28202).
        $this->requireArgCountRange($frame, 'ltrim', 1, 2);
        $string = self::vmStringArg($frame, 0, 'string');
        if ('' === $string) {
            if (null === $frame->returnVar) {
                return;
            }
            $frame->returnVar->string('');

            return;
        }
        [$mask, $mode] = VmString::resolveTrimMaskAndMode(
            \array_slice($frame->calledArgs, 1),
            'ltrim',
            VmString::TRIM_SIDE_LEFT
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::trimInt($string, $mask, $mode));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (!$this->requireArgCountRangeJit($context, $args, 'ltrim', 1, 2)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        if (
            !$context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant)
        ) {
            $emptyPtr = JitStringBuiltinArg::lowerTrimFamilyString(
                $context,
                $args[0],
                'ltrim',
                0,
                'string'
            );

            return $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $emptyPtr
            );
        }
        $literal = $args[0]->compileTimeString ?? null;
        $optional = \array_slice($args, 1);
        $optCount = \count($optional);
        $maskLiteral = 1 === $optCount ? ($optional[0]->compileTimeString ?? null) : null;
        if (null !== $literal && (0 === $optCount || null !== $maskLiteral)) {
            $mask = null !== $maskLiteral ? $maskLiteral : VmString::TRIM_DEFAULT;

            return $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load(
                    $context->constantStringFromString(
                        VmString::trimInt($literal, $mask, VmString::TRIM_SIDE_LEFT)
                    )
                )
            );
        }
        $mode = VmString::TRIM_SIDE_LEFT;
        $maskStr = null;
        if (1 === $optCount) {
            StringTrimMask::ensureLinked($context);
            $maskStr = JitStringBuiltinArg::lower($context, $optional[0], 'ltrim', 1, 'characters');
            $maskStr = $context->builder->call($context->lookupFunction('__string__separate'), $maskStr);
        }
        $str = self::jitStringArg($context, $args[0], 0, 'string');
        $early = string_trim::jitReturnIfCoercedEmptyTrimInput($context, $args[0], $str);
        if (null !== $early) {
            return $early;
        }
        $structName = $str->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $i64 = JitStringIndex::i64($context);
        $zero = JitStringIndex::zero($context);
        $charPtr = $context->builder->structGep($str, $map['value']);

        $startSlot = $context->builder->alloca($i64, 1, 'ltrim_start');
        $context->builder->store($zero, $startSlot);
        if ($mode & VmString::TRIM_SIDE_LEFT) {
            string_trim::advanceWhileTrimByte($context, $charPtr, $len, $startSlot, true, 'ltrim', $maskStr);
        }

        $start = $context->builder->load($startSlot);
        if ($mode & VmString::TRIM_SIDE_RIGHT) {
            $endSlot = $context->builder->alloca($i64, 1, 'ltrim_end');
            $context->builder->store($len, $endSlot);
            string_trim::advanceWhileTrimByte($context, $charPtr, $len, $endSlot, false, 'ltrim', $maskStr);
            $end = $context->builder->load($endSlot);
            $sliceLen = $context->builder->sub($end, $start);

            return string_trim::jitCopySlice($context, $str, $charPtr, $start, $sliceLen, 'ltrim');
        }

        $sliceLen = $context->builder->sub($len, $start);

        return string_trim::jitCopySlice($context, $str, $charPtr, $start, $sliceLen, 'ltrim');
    }

    /** php_trim — Zend 8.4 DEP+coerces null (not TypeError until 9.0); use soft-null path (#21404). */
    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        return VmString::trimFamilyStringArgForFrame(
            $frame,
            $argIndex,
            'ltrim',
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
                'ltrim',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'ltrim',
            $argIndex,
            $paramName
        );
    }
}
