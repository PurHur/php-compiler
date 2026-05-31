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
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('ltrim() requires one or two arguments');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('ltrim() only supports strings in this compiler build');
        }
        $mask = VmString::TRIM_DEFAULT;
        if (2 === $argc) {
            $maskArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_STRING !== $maskArg->type) {
                throw new \LogicException('ltrim() character mask must be a string in this compiler build');
            }
            $mask = $maskArg->toString();
        }
        $frame->returnVar->string(VmString::ltrim($v->toString(), $mask));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('ltrim() requires one or two arguments');
        }
        $literal = $args[0]->compileTimeString ?? null;
        $maskLiteral = (2 === $argc) ? ($args[1]->compileTimeString ?? null) : null;
        if (null !== $literal && (1 === $argc || null !== $maskLiteral)) {
            $mask = null !== $maskLiteral ? $maskLiteral : VmString::TRIM_DEFAULT;

            return $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load(
                    $context->constantStringFromString(VmString::ltrim($literal, $mask))
                )
            );
        }
        if (2 === $argc) {
            StringTrimMask::ensureLinked($context);
        }
        $str = $this->jitString($context, $args[0], 'string_ltrim() argument #1');
        $maskStr = (2 === $argc)
            ? $this->jitString($context, $args[1], 'string_ltrim() argument #2')
            : null;
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
        string_trim::advanceWhileTrimByte($context, $charPtr, $len, $startSlot, true, 'ltrim', $maskStr);

        $start = $context->builder->load($startSlot);
        $sliceLen = $context->builder->sub($len, $start);

        return string_trim::jitCopySlice($context, $str, $charPtr, $start, $sliceLen, 'ltrim');
    }
}
