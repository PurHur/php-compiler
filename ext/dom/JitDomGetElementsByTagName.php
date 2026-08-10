<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Builtin\DomGetElementsByTagNameRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for DOMDocument::getElementsByTagName() (#18461). */
final class JitDomGetElementsByTagName
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::getElementsByTagName() expects receiver and tag name');
        }

        // Compile-time null under strict_types: raise TypeError and stop — do not continue
        // into tag-list IR after a catchable throw (module verify: terminator mid-block; #29959).
        if ($context->callerStrictTypes && JITVariable::TYPE_NULL === $args[1]->type) {
            \PHPCompiler\JIT\JitNativeString::ensureInsertBlock($context);
            \PHPCompiler\JIT\ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'DOMDocument::getElementsByTagName(): Argument #1 ($qualifiedName) must be of type string, null given'
            );

            return self::boxNullResult($context);
        }

        // Soft-null: emit Z_PARAM_STR deprecation before UserScript '' fold (#29959).
        if (
            !$context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant)
        ) {
            self::loadStringArg($context, $args[1]);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_gebt_soft_null_cont');
        }

        if (JitDomGetElementsByTagNameUserScript::shouldUse($context)) {
            $us = JitDomGetElementsByTagNameUserScript::tryInvoke($context, ...$args);
            if (null !== $us) {
                return $us;
            }
        }

        DomGetElementsByTagNameRuntime::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_gebt_call_cont');

        $document = self::loadObjectArg($context, $args[0]);
        $tagStr = self::loadStringArg($context, $args[1]);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_gebt_after_tag');
        $listObj = $context->builder->call(
            $context->lookupFunction(DomGetElementsByTagNameRuntime::ABI_NAME),
            $document,
            $tagStr
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_gebt_post_call');

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

    private static function boxNullResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $ptr
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

        throw new \LogicException('DOMDocument::getElementsByTagName() receiver must be an object');
    }

    private static function loadStringArg(Context $context, JITVariable $arg): Value
    {
        // Z_PARAM_STR + caller strict_types — null must TypeError, not readString segfault (#29959).
        return JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $arg,
            'DOMDocument::getElementsByTagName',
            0,
            'qualifiedName'
        );
    }
}
