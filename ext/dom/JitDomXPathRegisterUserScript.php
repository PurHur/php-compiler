<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * User-script AOT: track DOMXPath::registerPhpFunctions() / registerNamespace() (#27575).
 *
 * DomInstanceMethodRuntime NestedJIT aborts on these methods under thin standalone;
 * compile-time side effects + {@see JitDomXPathEvaluateUserScript} php:function folds
 * match Zend without the generic instance bridge.
 *
 * php-src: ext/dom/xpath.c — registerPhpFunctions / registerNamespace
 */
final class JitDomXPathRegisterUserScript
{
    /** @see DomConstants::XPATH_REG_FUNC_MODE_* */
    private static int $phpFunctionsMode = 0;

    /** @var array<string, true> */
    private static array $phpFunctions = [];

    /** @var array<string, string> prefix → namespace URI */
    private static array $namespaces = [];

    public static function shouldUse(Context $context): bool
    {
        return JitDomLoadHTMLUserScript::shouldUse($context);
    }

    public static function reset(): void
    {
        self::$phpFunctionsMode = DomConstants::XPATH_REG_FUNC_MODE_NONE;
        self::$phpFunctions = [];
        self::$namespaces = [];
    }

    public static function isPhpFunctionAllowed(string $name): bool
    {
        if (DomConstants::XPATH_REG_FUNC_MODE_ALL === self::$phpFunctionsMode) {
            return true;
        }
        if (DomConstants::XPATH_REG_FUNC_MODE_SET === self::$phpFunctionsMode) {
            return isset(self::$phpFunctions[$name]);
        }

        return false;
    }

    public static function namespaceUri(string $prefix): ?string
    {
        return self::$namespaces[$prefix] ?? null;
    }

    /** @return array<string, string> */
    public static function namespaces(): array
    {
        return self::$namespaces;
    }

    /**
     * @return Value|null null = fall through to DomInstanceMethod (non-literal args)
     */
    public static function tryRegisterPhpFunctions(Context $context, JITVariable ...$args): ?Value
    {
        // Receiver only → allow all callables (php-src REG_FUNC_MODE_ALL).
        if (\count($args) < 2 || self::isOmittedOrNull($args[1])) {
            self::$phpFunctionsMode = DomConstants::XPATH_REG_FUNC_MODE_ALL;
            self::$phpFunctions = [];

            return self::boxNull($context);
        }
        $lit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null !== $lit) {
            if ('' === $lit || str_contains($lit, "\0")) {
                return null;
            }
            self::$phpFunctionsMode = DomConstants::XPATH_REG_FUNC_MODE_SET;
            self::$phpFunctions[$lit] = true;

            return self::boxNull($context);
        }

        return null;
    }

    /**
     * @return Value|null boxed bool true on success; null = fall through
     */
    public static function tryRegisterNamespace(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 3) {
            return null;
        }
        $prefix = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        $uri = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        if (null === $prefix || null === $uri) {
            return null;
        }
        // php-src xpath.c / xmlXPathRegisterNs — empty prefix → false (#29135).
        if ('' === $prefix) {
            return self::boxBool($context, false);
        }
        self::$namespaces[$prefix] = $uri;

        return self::boxBool($context, true);
    }

    private static function isOmittedOrNull(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type;
    }

    private static function boxNull(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    private static function boxBool(Context $context, bool $value): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt($value ? 1 : 0, false)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}
