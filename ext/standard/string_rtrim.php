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
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * rtrim() for strings (default whitespace or optional $characters mask; php-src string.c).
 */
final class string_rtrim extends Internal
{
    public function __construct()
    {
        parent::__construct('rtrim');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('rtrim() requires one or two arguments');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('rtrim() only supports strings in this compiler build');
        }
        $mask = VmString::TRIM_DEFAULT;
        if (2 === $argc) {
            $maskArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_STRING !== $maskArg->type) {
                throw new \LogicException('rtrim() character mask must be a string in this compiler build');
            }
            $mask = $maskArg->toString();
        }
        $frame->returnVar->string(VmString::rtrim($v->toString(), $mask));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('rtrim() requires one or two arguments');
        }
        $literal = $args[0]->compileTimeString ?? null;
        $maskLiteral = (2 === $argc) ? ($args[1]->compileTimeString ?? null) : null;
        if (null !== $literal && (1 === $argc || null !== $maskLiteral)) {
            $mask = null !== $maskLiteral ? $maskLiteral : VmString::TRIM_DEFAULT;

            return $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load(
                    $context->constantStringFromString(VmString::rtrim($literal, $mask))
                )
            );
        }
        if (2 === $argc) {
            StringTrimMask::ensureLinked($context);
        }
        $str = $this->jitString($context, $args[0], 'string_rtrim() argument #1');
        $maskStr = (2 === $argc)
            ? $this->jitString($context, $args[1], 'string_rtrim() argument #2')
            : null;
        $structName = $str->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $i64 = JitStringIndex::i64($context);
        $zero = JitStringIndex::zero($context);
        $charPtr = $context->builder->structGep($str, $map['value']);

        $endSlot = $context->builder->alloca($i64, 1, 'rtrim_end');
        $context->builder->store($len, $endSlot);
        string_trim::advanceWhileTrimByte($context, $charPtr, $len, $endSlot, false, 'rtrim', $maskStr);

        $end = $context->builder->load($endSlot);

        return string_trim::jitCopySlice($context, $str, $charPtr, $zero, $end, 'rtrim');
    }
}
