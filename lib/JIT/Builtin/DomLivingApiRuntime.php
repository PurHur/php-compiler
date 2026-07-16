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
 * Bool ABIs return int1 via {@see JitDomDocumentMethodKernel::ensureContextBridge()}
 * so assignCallResultOperand can materialize TYPE_NATIVE_BOOL.
 */
final class DomLivingApiRuntime
{
    public const ABI_CONTAINS = '__phpc_dom_living_contains';

    public const ABI_CONTAINS_NULL = '__phpc_dom_living_contains_null';

    public const ABI_GET_ROOT_NODE = '__phpc_dom_living_get_root_node';

    public const ABI_IS_EQUAL_NODE = '__phpc_dom_living_is_equal_node';

    public const ABI_TOGGLE_ATTRIBUTE = '__phpc_dom_living_toggle_attribute';

    public static function invokeContains(Context $context, Variable $receiver, Variable $other): Value
    {
        if (Variable::TYPE_NULL === $other->type) {
            JitDomDocumentMethodKernel::ensureContainsNullBridge($context);

            return $context->builder->call(
                $context->lookupFunction(self::ABI_CONTAINS_NULL),
                self::loadObject($context, $receiver)
            );
        }
        JitDomDocumentMethodKernel::ensureContainsBridge($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_CONTAINS),
            self::loadObject($context, $receiver),
            self::loadObject($context, $other)
        );
    }

    public static function invokeGetRootNode(Context $context, Variable $receiver): Value
    {
        JitDomDocumentMethodKernel::ensureGetRootNodeBridge($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_GET_ROOT_NODE),
            self::loadObject($context, $receiver)
        );
    }

    public static function invokeIsEqualNode(Context $context, Variable $receiver, Variable $other): Value
    {
        JitDomDocumentMethodKernel::ensureIsEqualNodeBridge($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_IS_EQUAL_NODE),
            self::loadObject($context, $receiver),
            self::loadObject($context, $other)
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

        return $context->builder->call(
            $context->lookupFunction(self::ABI_TOGGLE_ATTRIBUTE),
            self::loadObject($context, $receiver),
            JitStringArg::lower($context, $name, 'DOMElement::toggleAttribute() name'),
            $forceFlag
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
