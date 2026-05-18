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
 * str_contains() for two strings (subset of PHP 8).
 */
final class str_contains extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== count($frame->calledArgs)) {
            throw new \LogicException('str_contains() requires exactly two arguments');
        }
        $haystack = $frame->calledArgs[0]->resolveIndirect();
        $needle = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $haystack->type || Variable::TYPE_STRING !== $needle->type) {
            throw new \LogicException('str_contains() only supports strings in this compiler build');
        }
        $needleStr = $needle->toString();
        if ('' === $needleStr) {
            $frame->returnVar->bool(true);

            return;
        }
        $frame->returnVar->bool(
            false !== VmString::strpos($haystack->toString(), $needleStr)
        );
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (2 !== count($args)) {
            throw new \LogicException('str_contains() requires exactly two arguments');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type || JITVariable::TYPE_STRING !== $args[1]->type) {
            throw new \LogicException('str_contains() only supports strings in this compiler build');
        }
        $hayPtr = $this->stringDataPtr($context, $context->helper->loadValue($args[0]));
        $needlePtr = $this->stringDataPtr($context, $context->helper->loadValue($args[1]));
        $found = $context->builder->call($context->lookupFunction('strstr'), $hayPtr, $needlePtr);
        $null = $found->typeOf()->constPointerNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $found, $null);

        return $context->builder->not($isNull);
    }

    private function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $off = $context->structFieldMap[$structName]['value'];

        return $context->builder->structGep($strPtr, $off);
    }
}
