<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * User-script AOT link for DOM Living Standard methods (#19507).
 *
 * Bool ABI is int1 (DomLoadXML pattern). Lower call args before ensureBridge.
 * toggleAttribute uses omit / force-true / force-false ABIs (null force collapses in nested TUs).
 *
 * php-src: ext/dom/node.c, ext/dom/element.c
 */
final class DomLivingApiRuntime
{
    public const ABI_CONTAINS = '__phpc_dom_living_contains';

    public const ABI_CONTAINS_NULL = '__phpc_dom_living_contains_null';

    public const ABI_GET_ROOT_NODE = '__phpc_dom_living_get_root_node';

    public const ABI_IS_EQUAL_NODE = '__phpc_dom_living_is_equal_node';

    public const ABI_TOGGLE_ATTRIBUTE_OMIT = '__phpc_dom_living_toggle_omit';

    public const ABI_TOGGLE_ATTRIBUTE_FORCE_TRUE = '__phpc_dom_living_toggle_force_true';

    public const ABI_TOGGLE_ATTRIBUTE_FORCE_FALSE = '__phpc_dom_living_toggle_force_false';

    public static function invokeContains(Context $context, Variable $receiver, Variable $other): Value
    {
        if (Variable::TYPE_NULL === $other->type) {
            $receiverLlvm = self::loadObject($context, $receiver);
            JitDomDocumentMethodKernel::ensureContainsNullBridge($context);

            return $context->builder->call(
                $context->lookupFunction(self::ABI_CONTAINS_NULL),
                $receiverLlvm
            );
        }
        $receiverLlvm = self::loadObject($context, $receiver);
        $otherLlvm = self::loadObject($context, $other);
        JitDomDocumentMethodKernel::ensureContainsBridge($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_CONTAINS),
            $receiverLlvm,
            $otherLlvm
        );
    }

    public static function invokeGetRootNode(Context $context, Variable $receiver): Value
    {
        $receiverLlvm = self::loadObject($context, $receiver);
        JitDomDocumentMethodKernel::ensureGetRootNodeBridge($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_GET_ROOT_NODE),
            $receiverLlvm
        );
    }

    public static function invokeIsEqualNode(Context $context, Variable $receiver, Variable $other): Value
    {
        $receiverLlvm = self::loadObject($context, $receiver);
        $otherLlvm = self::loadObject($context, $other);
        JitDomDocumentMethodKernel::ensureIsEqualNodeBridge($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_IS_EQUAL_NODE),
            $receiverLlvm,
            $otherLlvm
        );
    }

    public static function invokeToggleAttribute(
        Context $context,
        Variable $receiver,
        Variable $name,
        ?Variable $force
    ): Value {
        $nameLlvm = JitStringArg::lower($context, $name, 'DOMElement::toggleAttribute() name');
        $receiverLlvm = self::loadObject($context, $receiver);
        $abi = self::ABI_TOGGLE_ATTRIBUTE_OMIT;
        if (null !== $force && Variable::TYPE_NULL !== $force->type) {
            if (Variable::TYPE_NATIVE_BOOL === $force->type) {
                $raw = $context->helper->loadValue($force);
                if (method_exists($raw, 'isConstant') && $raw->isConstant() && method_exists($raw, 'getConstantValue')) {
                    $abi = ((int) $raw->getConstantValue() !== 0)
                        ? self::ABI_TOGGLE_ATTRIBUTE_FORCE_TRUE
                        : self::ABI_TOGGLE_ATTRIBUTE_FORCE_FALSE;
                }
            } elseif (Variable::TYPE_NATIVE_LONG === $force->type && null !== $force->compileTimeLong) {
                $abi = (0 !== $force->compileTimeLong)
                    ? self::ABI_TOGGLE_ATTRIBUTE_FORCE_TRUE
                    : self::ABI_TOGGLE_ATTRIBUTE_FORCE_FALSE;
            }
        }
        if (self::ABI_TOGGLE_ATTRIBUTE_FORCE_TRUE === $abi) {
            JitDomDocumentMethodKernel::ensureToggleAttributeForceTrueBridge($context);
        } elseif (self::ABI_TOGGLE_ATTRIBUTE_FORCE_FALSE === $abi) {
            JitDomDocumentMethodKernel::ensureToggleAttributeForceFalseBridge($context);
        } else {
            JitDomDocumentMethodKernel::ensureToggleAttributeOmitBridge($context);
        }

        return $context->builder->call(
            $context->lookupFunction($abi),
            $receiverLlvm,
            $nameLlvm
        );
    }

    private static function loadObject(Context $context, Variable $arg): Value
    {
        if (Variable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOM living API arg must be object or value box');
    }
}
