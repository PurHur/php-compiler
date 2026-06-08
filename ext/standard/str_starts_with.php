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
 * str_starts_with() for two strings (subset of PHP 8).
 */
final class str_starts_with extends Internal
{
    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'str_starts_with', 2);
        $haystackStr = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'str_starts_with',
            0,
            'haystack'
        );
        $needleStr = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'str_starts_with',
            1,
            'needle'
        );
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->bool(VmString::startsWith($haystackStr, $needleStr))
        );
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (!$this->requireExactJitArgCount($context, $args, 'str_starts_with', 2)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $hay = JitStringBuiltinArg::lower($context, $args[0], 'str_starts_with', 0, 'haystack');
        $needle = JitStringBuiltinArg::lower($context, $args[1], 'str_starts_with', 1, 'needle');
        $hayMap = $context->structFieldMap[$hay->typeOf()->getElementType()->getName()];
        $needleMap = $context->structFieldMap[$needle->typeOf()->getElementType()->getName()];
        $hayLen = $context->builder->load($context->builder->structGep($hay, $hayMap['length']));
        $needleLen = $context->builder->load($context->builder->structGep($needle, $needleMap['length']));
        $tooLong = $context->builder->icmp(Builder::INT_ULT, $hayLen, $needleLen);
        $hayPtr = $context->builder->structGep($hay, $hayMap['value']);
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
            $hayPtr,
            $needlePtr,
            $compareLen
        );
        $zero = $cmp->typeOf()->constInt(0, false);
        $matches = $context->builder->icmp(Builder::INT_EQ, $cmp, $zero);
        $ok = $context->builder->and($context->builder->not($tooLong), $matches);

        return $context->builder->select($tooLong, $context->constantFromBool(false), $ok);
    }
}
