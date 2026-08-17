<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Builtin\DomXPathQueryRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for DOMXPath::query() (#18493). */
final class JitDomXPathQuery
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMXPath::query() expects receiver and expression');
        }

        // Compile-time null under strict_types: raise TypeError before user-script (#30041).
        if ($context->callerStrictTypes && JITVariable::TYPE_NULL === $args[1]->type) {
            \PHPCompiler\JIT\JitNativeString::ensureInsertBlock($context);
            \PHPCompiler\JIT\ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'DOMXPath::query(): Argument #1 ($expression) must be of type string, null given'
            );
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

            return JitValueBox::normalizeValuePtr($context, $ptr);
        }

        if (JitDomXPathQueryUserScript::shouldUse($context)) {
            $us = JitDomXPathQueryUserScript::tryInvoke($context, ...$args);
            if (null !== $us) {
                return $us;
            }
        }

        DomXPathQueryRuntime::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_xpath_query_call_cont');

        $xpath = self::loadObjectArg($context, $args[0]);
        $exprStr = self::loadStringArg($context, $args[1]);
        $listObj = $context->builder->call(
            $context->lookupFunction(DomXPathQueryRuntime::ABI_NAME),
            $xpath,
            $exprStr
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_xpath_query_post_call');

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            return self::boxObjectResult($context, $listObj);
        }

        return $listObj;
    }

    private static function boxObjectResult(Context $context, Value $object): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $object
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
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

        throw new \LogicException('DOMXPath::query() receiver must be an object');
    }

    private static function loadStringArg(Context $context, JITVariable $arg): Value
    {
        // Z_PARAM_STR + caller strict_types — null must TypeError (#30041).
        return JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $arg,
            'DOMXPath::query',
            0,
            'expression'
        );
    }
}
