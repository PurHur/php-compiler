<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomXPathRegisterUserScript;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomInstanceMethodRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMXPath::registerNamespace() — user-script AOT (#27575). */
final class DomXPathRegisterNamespace implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_xpath_register_namespace_cont');
        if (\count($args) < 3) {
            throw new \LogicException('DOMXPath::registerNamespace() expects receiver, prefix, and URI');
        }
        // Z_PARAM_STR: strict null → TypeError before user-script fold (#30301, sibling #30041).
        if ($context->callerStrictTypes) {
            if (Variable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant) {
                return self::emitNullStringTypeError(
                    $context,
                    'DOMXPath::registerNamespace(): Argument #1 ($prefix) must be of type string, null given'
                );
            }
            if (Variable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant) {
                return self::emitNullStringTypeError(
                    $context,
                    'DOMXPath::registerNamespace(): Argument #2 ($namespace) must be of type string, null given'
                );
            }
        }
        if (JitDomXPathRegisterUserScript::shouldUse($context)) {
            $us = JitDomXPathRegisterUserScript::tryRegisterNamespace($context, ...$args);
            if (null !== $us) {
                return $us;
            }
        }
        $extra = \array_slice($args, 1);

        return DomInstanceMethodRuntime::invoke(
            $context,
            \count($extra),
            'registernamespace',
            $args[0],
            ...$extra
        );
    }

    private static function emitNullStringTypeError(Context $context, string $message): Value
    {
        JitNativeString::ensureInsertBlock($context);
        ExceptionBridge::emitTypeErrorAndAbort($context, $message);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
