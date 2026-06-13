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
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * str_ends_with() for two strings (subset of PHP 8).
 */
final class str_ends_with extends Internal
{
    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'str_ends_with', 2);
        $haystackStr = VmString::requireStringBuiltinArg(
            $frame->calledArgs[0],
            'str_ends_with',
            0,
            'haystack'
        );
        $needleStr = VmString::requireStringBuiltinArg(
            $frame->calledArgs[1],
            'str_ends_with',
            1,
            'needle'
        );
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->bool(VmString::endsWith($haystackStr, $needleStr))
        );
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (!$this->requireExactJitArgCount($context, $args, 'str_ends_with', 2)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $hay = JitStringBuiltinArg::lowerRequiredString($context, $args[0], 'str_ends_with', 0, 'haystack');
        $needle = JitStringBuiltinArg::lowerRequiredString($context, $args[1], 'str_ends_with', 1, 'needle');
        $hayMap = $context->structFieldMap[$hay->typeOf()->getElementType()->getName()];
        $needleMap = $context->structFieldMap[$needle->typeOf()->getElementType()->getName()];
        $hayLen = $context->builder->load($context->builder->structGep($hay, $hayMap['length']));
        $needleLen = $context->builder->load($context->builder->structGep($needle, $needleMap['length']));
        $zero = $hayLen->typeOf()->constInt(0, false);
        $tooLong = $context->builder->icmp(Builder::INT_ULT, $hayLen, $needleLen);
        $isEmptyNeedle = $context->builder->icmp(Builder::INT_EQ, $needleLen, $zero);
        $start = $context->builder->sub($hayLen, $needleLen);
        $hayPtr = $context->builder->structGep($hay, $hayMap['value']);
        $suffixPtr = $context->builder->gep($hayPtr, $start);
        $needlePtr = $context->builder->structGep($needle, $needleMap['value']);
        $compareLen = $context->builder->zExt(
            $context->builder->trunc(
                $needleLen,
                $context->getTypeFromString('int32')
            ),
            $context->getTypeFromString('size_t')
        );
        $cmp = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $suffixPtr,
            $needlePtr,
            $compareLen
        );
        $cmpZero = $cmp->typeOf()->constInt(0, false);
        $matches = $context->builder->icmp(Builder::INT_EQ, $cmp, $cmpZero);
        $ok = $context->builder->and($context->builder->not($tooLong), $matches);

        return $context->builder->select(
            $isEmptyNeedle,
            $context->constantFromBool(true),
            $context->builder->select($tooLong, $context->constantFromBool(false), $ok)
        );
    }
}
