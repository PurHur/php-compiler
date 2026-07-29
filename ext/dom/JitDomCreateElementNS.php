<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\DomCreateElementNSRuntime;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for DOMDocument::createElementNS() (#14314, #18938). */
final class JitDomCreateElementNS
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \LogicException('DOMDocument::createElementNS() expects receiver, namespace, and qualified name');
        }

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            return self::invokeViaHelper($context, ...$args);
        }

        throw new \LogicException('DOMDocument::createElementNS() requires user-script AOT helper in this compiler build');
    }

    private static function invokeViaHelper(Context $context, JITVariable ...$args): Value
    {
        DomCreateElementNSRuntime::ensureLinked($context);

        $document = self::loadObjectArg($context, $args[0]);
        $namespace = self::loadStringArg($context, $args[1]);
        $qualifiedName = self::loadStringArg($context, $args[2]);
        $value = \count($args) >= 4
            ? self::loadStringArg($context, $args[3])
            : $context->builder->load($context->constantStringFromString(''));

        $element = $context->builder->call(
            $context->lookupFunction(DomCreateElementNSRuntime::ABI_NAME),
            $document,
            $namespace,
            $qualifiedName,
            $value
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $element
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

        throw new \LogicException('DOMDocument::createElementNS() receiver must be an object');
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

        throw new \LogicException('DOMDocument::createElementNS() string argument has invalid type');
    }
}
