<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Builtin\DomXPathEvaluateRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for DOMXPath::evaluate() (#18526). */
final class JitDomXPathEvaluate
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMXPath::evaluate() expects receiver and expression');
        }

        if (JitDomXPathEvaluateUserScript::shouldUse($context)) {
            $us = JitDomXPathEvaluateUserScript::tryInvoke($context, ...$args);
            if (null !== $us) {
                return $us;
            }
        }

        $exprLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        $expr = null !== $exprLit ? trim($exprLit) : null;
        if (null !== $expr && preg_match('~^boolean\(~i', $expr)) {
            DomXPathEvaluateRuntime::ensureBoolLinked($context);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_xpath_evaluate_bool_cont');

            return $context->builder->call(
                $context->lookupFunction(DomXPathEvaluateRuntime::ABI_BOOL),
                self::loadObjectArg($context, $args[0]),
                self::loadStringArg($context, $args[1])
            );
        }
        if (null !== $expr && preg_match('~^(number|count)\(~i', $expr)) {
            DomXPathEvaluateRuntime::ensureDoubleLinked($context);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_xpath_evaluate_double_cont');

            return $context->builder->call(
                $context->lookupFunction(DomXPathEvaluateRuntime::ABI_DOUBLE),
                self::loadObjectArg($context, $args[0]),
                self::loadStringArg($context, $args[1])
            );
        }
        if (null !== $expr && preg_match('~^string\(~i', $expr)) {
            DomXPathEvaluateRuntime::ensureStringLinked($context);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_xpath_evaluate_string_cont');

            return $context->builder->call(
                $context->lookupFunction(DomXPathEvaluateRuntime::ABI_STRING),
                self::loadObjectArg($context, $args[0]),
                self::loadStringArg($context, $args[1])
            );
        }

        throw new \LogicException('DOMXPath::evaluate() user-script AOT requires boolean()/count()/number()/string() literal');
    }

    private static function loadObjectArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOMXPath::evaluate() receiver must be an object');
    }

    private static function loadStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOMXPath::evaluate() expression must be a string');
    }
}
