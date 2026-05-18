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
use PHPCompiler\JIT\Variable as JITVariable;
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
        if (2 !== count($frame->calledArgs)) {
            throw new \LogicException('str_starts_with() requires exactly two arguments');
        }
        $haystack = $frame->calledArgs[0]->resolveIndirect();
        $needle = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $haystack->type || Variable::TYPE_STRING !== $needle->type) {
            throw new \LogicException('str_starts_with() only supports strings in this compiler build');
        }
        $hay = $haystack->toString();
        $needleStr = $needle->toString();
        $nlen = \strlen($needleStr);
        if ($nlen > \strlen($hay)) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(0 === \strncmp($hay, $needleStr, $nlen));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (2 !== count($args)) {
            throw new \LogicException('str_starts_with() requires exactly two arguments');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type || JITVariable::TYPE_STRING !== $args[1]->type) {
            throw new \LogicException('str_starts_with() only supports strings in this compiler build');
        }
        $hay = $context->helper->loadValue($args[0]);
        $needle = $context->helper->loadValue($args[1]);
        $hayMap = $context->structFieldMap[$hay->typeOf()->getElementType()->getName()];
        $needleMap = $context->structFieldMap[$needle->typeOf()->getElementType()->getName()];
        $hayLen = $context->builder->load($context->builder->structGep($hay, $hayMap['length']));
        $needleLen = $context->builder->load($context->builder->structGep($needle, $needleMap['length']));
        $tooLong = $context->builder->icmp(Builder::INT_ULT, $hayLen, $needleLen);
        $hayPtr = $context->builder->structGep($hay, $hayMap['value']);
        $needlePtr = $context->builder->structGep($needle, $needleMap['value']);
        $cmp = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $hayPtr,
            $needlePtr,
            $needleLen
        );
        $zero = $cmp->typeOf()->constInt(0, false);
        $matches = $context->builder->icmp(Builder::INT_EQ, $cmp, $zero);
        $ok = $context->builder->and($context->builder->not($tooLong), $matches);

        return $context->builder->select($tooLong, $context->constantFromBool(false), $ok);
    }
}
