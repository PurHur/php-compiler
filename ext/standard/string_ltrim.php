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
use PHPCompiler\JIT\Builtin\StringTrimModeJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
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
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('ltrim() requires one to three arguments');
        }
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'ltrim', 'string', 0, $frame);
        $string = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'ltrim', 0, 'string');
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
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('ltrim() requires one to three arguments');
        }
        JitInternalStrictArg::rejectNullString($context, $args[0], 'ltrim', 'string', 1);
        $literal = $args[0]->compileTimeString ?? null;
        $optional = \array_slice($args, 1);
        $optCount = \count($optional);
        $modeLiteral = null;
        $maskLiteral = null;
        if (1 === $optCount) {
            $modeLiteral = StringTrimModeJit::compileTimeModeBitmask($context, $optional[0]);
            $maskLiteral = null === $modeLiteral ? ($optional[0]->compileTimeString ?? null) : null;
        } elseif (2 === $optCount) {
            $maskLiteral = $optional[0]->compileTimeString ?? null;
            $modeLiteral = StringTrimModeJit::compileTimeModeBitmask($context, $optional[1]);
        }
        if (null !== $literal && (0 === $optCount || null !== $maskLiteral || null !== $modeLiteral)) {
            $mask = null !== $maskLiteral ? $maskLiteral : VmString::TRIM_DEFAULT;
            $mode = null !== $modeLiteral ? $modeLiteral : VmString::TRIM_SIDE_LEFT;

            return $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load(
                    $context->constantStringFromString(VmString::trimInt($literal, $mask, $mode))
                )
            );
        }
        $mode = VmString::TRIM_SIDE_LEFT;
        $maskStr = null;
        if (1 === $optCount) {
            $modeLiteral = StringTrimModeJit::compileTimeModeBitmask($context, $optional[0]);
            if (null !== $modeLiteral) {
                $mode = $modeLiteral;
            } else {
                StringTrimMask::ensureLinked($context);
                $maskStr = JitStringBuiltinArg::lower($context, $optional[0], 'ltrim', 1, 'characters');
                $maskStr = $context->builder->call($context->lookupFunction('__string__separate'), $maskStr);
            }
        } elseif (2 === $optCount) {
            StringTrimMask::ensureLinked($context);
            $maskStr = JitStringBuiltinArg::lower($context, $optional[0], 'ltrim', 1, 'characters');
            $maskStr = $context->builder->call($context->lookupFunction('__string__separate'), $maskStr);
            $modeLiteral = StringTrimModeJit::compileTimeModeBitmask($context, $optional[1]);
            if (null !== $modeLiteral) {
                $mode = $modeLiteral;
            }
        }
        $str = JitStringBuiltinArg::lower($context, $args[0], 'ltrim', 0, 'string');
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
}
