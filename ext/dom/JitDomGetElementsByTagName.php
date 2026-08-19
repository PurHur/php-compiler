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

    /**
     * DOMDocument::getElementsByTagNameNS() — user-script AOT (#32415).
     *
     * php-src: ext/dom/php_dom.c PHP_METHOD(DOMDocument, getElementsByTagNameNS).
     */
    public static function invokeNS(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \LogicException(
                'DOMDocument::getElementsByTagNameNS() expects receiver, namespace, and localName'
            );
        }

        // Z_PARAM_STR localName under strict_types — null must TypeError (#29959 peer).
        if ($context->callerStrictTypes && JITVariable::TYPE_NULL === $args[2]->type) {
            \PHPCompiler\JIT\JitNativeString::ensureInsertBlock($context);
            \PHPCompiler\JIT\ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'DOMDocument::getElementsByTagNameNS(): Argument #2 ($localName) must be of type string, null given'
            );

            return self::boxNullResult($context);
        }

        if (JitDomGetElementsByTagNameUserScript::shouldUse($context)) {
            $us = JitDomGetElementsByTagNameUserScript::tryInvokeNS($context, ...$args);
            if (null !== $us) {
                return $us;
            }
            throw new \LogicException(
                'DOMDocument::getElementsByTagNameNS() user-script AOT requires compile-time namespace, localName, and loadXML'
            );
        }

        throw new \LogicException(
            'DOMDocument::getElementsByTagNameNS() JIT helper is user-script AOT only in this build'
        );
    }

    /**
     * DOMElement::getElementsByTagName() — user-script AOT (#32454).
     *
     * php-src: ext/dom/element.c PHP_METHOD(DOMElement, getElementsByTagName).
     */
    public static function invokeFromElement(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMElement::getElementsByTagName() expects receiver and tag name');
        }

        if ($context->callerStrictTypes && JITVariable::TYPE_NULL === $args[1]->type) {
            \PHPCompiler\JIT\JitNativeString::ensureInsertBlock($context);
            \PHPCompiler\JIT\ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'DOMElement::getElementsByTagName(): Argument #1 ($qualifiedName) must be of type string, null given'
            );

            return self::boxNullResult($context);
        }

        if (
            !$context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant)
        ) {
            self::loadStringArgElement($context, $args[1]);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_el_gebt_soft_null_cont');
        }

        if (JitDomGetElementsByTagNameUserScript::shouldUse($context)) {
            $us = JitDomGetElementsByTagNameUserScript::tryInvokeFromElement($context, ...$args);
            if (null !== $us) {
                return $us;
            }
            throw new \LogicException(
                'DOMElement::getElementsByTagName() user-script AOT requires compile-time qualifiedName and loadXML'
            );
        }

        throw new \LogicException(
            'DOMElement::getElementsByTagName() JIT helper is user-script AOT only in this build'
        );
    }

    /**
     * DOMElement::getElementsByTagNameNS() — user-script AOT (#32511).
     *
     * php-src: ext/dom/element.c PHP_METHOD(DOMElement, getElementsByTagNameNS).
     */
    public static function invokeFromElementNS(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \LogicException(
                'DOMElement::getElementsByTagNameNS() expects receiver, namespace, and localName'
            );
        }

        if ($context->callerStrictTypes && JITVariable::TYPE_NULL === $args[2]->type) {
            \PHPCompiler\JIT\JitNativeString::ensureInsertBlock($context);
            \PHPCompiler\JIT\ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'DOMElement::getElementsByTagNameNS(): Argument #2 ($localName) must be of type string, null given'
            );

            return self::boxNullResult($context);
        }

        if (JitDomGetElementsByTagNameUserScript::shouldUse($context)) {
            $us = JitDomGetElementsByTagNameUserScript::tryInvokeFromElementNS($context, ...$args);
            if (null !== $us) {
                return $us;
            }
            throw new \LogicException(
                'DOMElement::getElementsByTagNameNS() user-script AOT requires compile-time namespace, localName, and loadXML'
            );
        }

        throw new \LogicException(
            'DOMElement::getElementsByTagNameNS() JIT helper is user-script AOT only in this build'
        );
    }

    private static function loadStringArgElement(Context $context, JITVariable $arg): Value
    {
        return JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $arg,
            'DOMElement::getElementsByTagName',
            0,
            'qualifiedName'
        );
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
