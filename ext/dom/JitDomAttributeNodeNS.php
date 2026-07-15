<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomImportNodeRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for DOMElement AttributeNodeNS + DOMDocument::createAttributeNS (#19265). */
final class JitDomAttributeNodeNS
{
    public static function invokeGet(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \LogicException('DOMElement::getAttributeNodeNS() expects receiver, namespace, and localName');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_getattrnodens_cont');
        DomImportNodeRuntime::ensureGetAttributeNodeNSLinked($context);

        $element = self::loadObjectArg($context, $args[0], 'DOMElement::getAttributeNodeNS() receiver');
        $namespace = self::loadStringArg($context, $args[1]);
        $localName = self::loadStringArg($context, $args[2]);
        $attr = $context->builder->call(
            $context->lookupFunction(DomImportNodeRuntime::ABI_GET_ATTRIBUTE_NODE_NS),
            $element,
            $namespace,
            $localName
        );

        return self::boxObjectResult($context, $attr);
    }

    public static function invokeSet(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMElement::setAttributeNodeNS() expects receiver and attr');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_setattrnodens_cont');
        DomImportNodeRuntime::ensureSetAttributeNodeNSLinked($context);

        $element = self::loadObjectArg($context, $args[0], 'DOMElement::setAttributeNodeNS() receiver');
        $attr = self::loadObjectArg($context, $args[1], 'DOMElement::setAttributeNodeNS() attr');
        $replaced = $context->builder->call(
            $context->lookupFunction(DomImportNodeRuntime::ABI_SET_ATTRIBUTE_NODE_NS),
            $element,
            $attr
        );

        return self::boxObjectResult($context, $replaced);
    }

    public static function invokeCreate(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \LogicException('DOMDocument::createAttributeNS() expects receiver, namespace, and qualifiedName');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_createattrns_cont');
        DomImportNodeRuntime::ensureCreateAttributeNSLinked($context);

        $document = self::loadObjectArg($context, $args[0], 'DOMDocument::createAttributeNS() receiver');
        $namespace = self::loadStringArg($context, $args[1]);
        $qualifiedName = self::loadStringArg($context, $args[2]);
        $attr = $context->builder->call(
            $context->lookupFunction(DomImportNodeRuntime::ABI_CREATE_ATTRIBUTE_NS),
            $document,
            $namespace,
            $qualifiedName
        );

        return self::boxObjectResult($context, $attr);
    }

    private static function loadObjectArg(Context $context, JITVariable $arg, string $label): Value
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

        throw new \LogicException($label.' must be an object');
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
        if (JITVariable::TYPE_NULL === $arg->type) {
            return $context->builder->load($context->constantStringFromString(''));
        }

        throw new \LogicException('AttributeNodeNS string argument must be string or null');
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

}
