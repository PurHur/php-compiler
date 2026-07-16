<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * User-script AOT link for DOM Living Standard methods (#19507).
 *
 * Bool helpers return int64; caller icmp→int1 (int1 bridge coerce was always-false).
 * Object helpers return __object__*; boxing happens here in the caller.
 */
final class DomLivingApiRuntime
{
    public const ABI_CONTAINS = '__phpc_dom_living_contains_i64';

    public const ABI_CONTAINS_NULL = '__phpc_dom_living_contains_null_i64';

    public const ABI_GET_ROOT_NODE = '__phpc_dom_living_get_root_node';

    public const ABI_IS_EQUAL_NODE = '__phpc_dom_living_is_equal_node_i64';

    public const ABI_TOGGLE_ATTRIBUTE = '__phpc_dom_living_toggle_attribute_i64';

    public static function invokeContains(Context $context, Variable $receiver, Variable $other): Value
    {
        if (Variable::TYPE_NULL === $other->type) {
            JitDomDocumentMethodKernel::ensureContainsNullBridge($context);

            return self::i64ToBool(
                $context,
                $context->builder->call(
                    $context->lookupFunction(self::ABI_CONTAINS_NULL),
                    self::loadObject($context, $receiver)
                )
            );
        }
        JitDomDocumentMethodKernel::ensureContainsBridge($context);

        return self::i64ToBool(
            $context,
            $context->builder->call(
                $context->lookupFunction(self::ABI_CONTAINS),
                self::loadObject($context, $receiver),
                self::loadObject($context, $other)
            )
        );
    }

    public static function invokeGetRootNode(Context $context, Variable $receiver): Value
    {
        JitDomDocumentMethodKernel::ensureGetRootNodeBridge($context);
        $rootObj = $context->builder->call(
            $context->lookupFunction(self::ABI_GET_ROOT_NODE),
            self::loadObject($context, $receiver)
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $rootObj
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    public static function invokeIsEqualNode(Context $context, Variable $receiver, Variable $other): Value
    {
        JitDomDocumentMethodKernel::ensureIsEqualNodeBridge($context);

        return self::i64ToBool(
            $context,
            $context->builder->call(
                $context->lookupFunction(self::ABI_IS_EQUAL_NODE),
                self::loadObject($context, $receiver),
                self::loadObject($context, $other)
            )
        );
    }

    public static function invokeToggleAttribute(
        Context $context,
        Variable $receiver,
        Variable $name,
        ?Variable $force
    ): Value {
        JitDomDocumentMethodKernel::ensureToggleAttributeBridge($context);
        $forceFlag = $context->getTypeFromString('int64')->constInt(-1, true);
        if (null !== $force) {
            if (Variable::TYPE_NULL === $force->type) {
                $forceFlag = $context->getTypeFromString('int64')->constInt(-1, true);
            } elseif (Variable::TYPE_NATIVE_BOOL === $force->type) {
                $forceFlag = $context->builder->select(
                    $context->helper->loadValue($force),
                    $context->getTypeFromString('int64')->constInt(1, true),
                    $context->getTypeFromString('int64')->constInt(0, true)
                );
            } elseif (Variable::TYPE_NATIVE_LONG === $force->type) {
                $forceFlag = $context->helper->loadValue($force);
            }
        }

        // DEBUG probe: return raw i64 (forceFlag encoding) as native long — no icmp.
        return $context->builder->call(
            $context->lookupFunction(self::ABI_TOGGLE_ATTRIBUTE),
            self::loadObject($context, $receiver),
            JitStringArg::lower($context, $name, 'DOMElement::toggleAttribute() name'),
            $forceFlag
        );
    }

    private static function i64ToBool(Context $context, Value $i64): Value
    {
        return $context->builder->icmp(
            Builder::INT_NE,
            $i64,
            $context->getTypeFromString('int64')->constInt(0, false)
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
