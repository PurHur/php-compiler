<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Func\Internal as CoreFuncInternal;

/**
 * Discarded pure-call elision for exists / class / object introspection
 * builtins (#36387).
 *
 * Extracted from {@see DiscardedPureCallElision} so the hub is not one monolith
 * TU on the gen-0 spine (split-TU / size-budget ratchet). Shared arg predicates
 * ({@code stringArgAllowsDiscardedElision}, {@code mathArgAllowsDiscardedElision},
 * {@code isTypedArrayArg}) stay on the hub class.
 *
 * Used via {@code use DiscardedPureCallElisionIntrospectOps;} on
 * {@see DiscardedPureCallElision}.
 *
 * No new C ABI. php-src: Zend/zend_builtin_functions.c, ext/standard/info.c,
 * ext/standard/basic_functions.c, ext/standard/array.c, ext/standard/class.c,
 * ext/standard/var.c, ext/spl/php_spl.c, ext/spl/spl_functions.c.
 */
trait DiscardedPureCallElisionIntrospectOps
{
    /**
     * Discarded {@code function_exists} on typed / literal string — php-src
     * {@code Zend/zend_builtin_functions.c}. Soft-null stays live (deprecate).
     * No autoload side effects (unlike {@code class_exists}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureFunctionExistsNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureFunctionExistsBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::functionExistsArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code extension_loaded} on typed / literal string — php-src
     * {@code ext/standard/info.c}. Soft-null stays live (deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureExtensionLoadedNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureExtensionLoadedBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::extensionLoadedArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code defined} on typed / literal string — php-src
     * {@code ext/standard/basic_functions.c}. Soft-null stays live (deprecate).
     * No autoload side effects.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureDefinedNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureDefinedBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::definedArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code method_exists} on typed object + typed / literal method
     * string — php-src {@code Zend/zend_builtin_functions.c}. String class-name
     * receivers stay live (autoload). Soft-null method stays live (deprecate).
     * Null / non-object|string receivers stay live ({@code TypeError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureMethodExistsNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureMethodExistsBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::methodExistsArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code array_key_exists}/{@code key_exists} on typed array +
     * non-null scalar key — php-src {@code ext/standard/array.c}. Soft-null
     * keys stay live (deprecate). Object / value-box keys stay live. Non-array
     * haystacks stay live ({@code TypeError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureArrayKeyExistsNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureArrayKeyExistsBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::arrayKeyExistsArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code property_exists} on typed object + typed / literal
     * property string — php-src {@code Zend/zend_builtin_functions.c}. Peer
     * {@see tryElidePureMethodExistsNoSideEffect}.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePurePropertyExistsNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPurePropertyExistsBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::propertyExistsArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code class_exists}/{@code interface_exists}/
     * {@code trait_exists}/{@code enum_exists} on typed / literal string +
     * compile-time-false {@code $autoload} — php-src
     * {@code Zend/zend_builtin_functions.c}. Default / true / dynamic
     * autoload stays live (spl_autoload). Soft-null name/autoload stay live
     * (deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureClassExistsFamilyNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureClassExistsFamilyBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::classExistsFamilyArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code get_class}/{@code get_parent_class}/{@code spl_object_id}/
     * {@code spl_object_hash} on a typed object — php-src
     * {@code Zend/zend_builtin_functions.c} / {@code ext/spl/php_spl.c}. String
     * {@code get_parent_class} stays live (autoload). Soft-null / non-object
     * stay live ({@code TypeError}). Zero-arg stay live (deprecation / scope).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureObjectIntrospectNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureObjectIntrospectBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::objectIntrospectArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code is_a}/{@code is_subclass_of} on a typed object + typed /
     * literal class string — php-src {@code Zend/zend_builtin_functions.c}.
     * Object subjects never autoload; string subjects stay live. Soft-null
     * class / allow_string stay live (deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureIsAFamilyNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureIsAFamilyBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::isAFamilyArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code class_parents}/{@code class_implements}/{@code class_uses}
     * on a typed object (+ optional typed bool {@code $autoload}) — php-src
     * {@code ext/standard/class.c}/{@code basic_functions.c}/{@code spl_functions.c}.
     * Object subjects never autoload; string subjects stay live. Soft-null
     * {@code $autoload} stays live (deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureClassHierarchyNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureClassHierarchyBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::classHierarchyArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code get_object_vars}/{@code get_mangled_object_vars}/
     * {@code get_class_methods} on a typed object — php-src
     * {@code Zend/zend_builtin_functions.c}/{@code ext/standard/var.c}.
     * Object operands never autoload; string {@code get_class_methods} stays
     * live. Soft-null / non-object stay live ({@code TypeError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureObjectVarsMethodsNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureObjectVarsMethodsBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::objectVarsMethodsArgsAllowDiscardedElision($callArgs);
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function functionExistsArgsAllowDiscardedElision(array $callArgs): bool
    {
        if (
            !isset($callArgs[0])
            || !$callArgs[0] instanceof Variable
            || isset($callArgs[1])
        ) {
            return false;
        }

        return self::stringArgAllowsDiscardedElision($callArgs[0]);
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function extensionLoadedArgsAllowDiscardedElision(array $callArgs): bool
    {
        return self::functionExistsArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Exactly one typed / literal string — soft-null stays live (deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function definedArgsAllowDiscardedElision(array $callArgs): bool
    {
        return self::functionExistsArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Typed hashtable / native array + non-null scalar key (string / long /
     * double / bool). Soft-null keys deprecate; object / value-box keys stay
     * live.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function arrayKeyExistsArgsAllowDiscardedElision(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1])
            || isset($callArgs[2])
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
        ) {
            return false;
        }
        if (!self::isTypedArrayArg($callArgs[1])) {
            return false;
        }
        $key = $callArgs[0];
        if ($key->isNullConstant || Variable::TYPE_NULL === $key->type) {
            return false;
        }
        if (Variable::TYPE_OBJECT === $key->type || Variable::TYPE_VALUE === $key->type) {
            return false;
        }
        if (self::stringArgAllowsDiscardedElision($key)) {
            return true;
        }

        return self::mathArgAllowsDiscardedElision($key);
    }

    /**
     * Typed object + typed / literal method string — string class names /
     * soft-null / value-box stay live.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function methodExistsArgsAllowDiscardedElision(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1])
            || isset($callArgs[2])
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
        ) {
            return false;
        }
        if (Variable::TYPE_OBJECT !== $callArgs[0]->type) {
            return false;
        }

        return self::stringArgAllowsDiscardedElision($callArgs[1]);
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function propertyExistsArgsAllowDiscardedElision(array $callArgs): bool
    {
        return self::methodExistsArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Typed / literal class name + compile-time-false {@code $autoload}.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function classExistsFamilyArgsAllowDiscardedElision(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1])
            || isset($callArgs[2])
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
            || !self::stringArgAllowsDiscardedElision($callArgs[0])
        ) {
            return false;
        }

        return NoThrowCallElision::isCompileTimeFalseAutoloadArg($callArgs[1]);
    }

    /**
     * Exactly one typed object — peer {@see NoThrowCallElision::objectIntrospectArgsCannotThrow}.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function objectIntrospectArgsAllowDiscardedElision(array $callArgs): bool
    {
        return NoThrowCallElision::objectIntrospectArgsCannotThrow($callArgs);
    }

    /**
     * Typed object + typed / literal class string + optional non-null bool-ish
     * {@code $allow_string} — peer {@see NoThrowCallElision::isAFamilyArgsCannotThrow}.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function isAFamilyArgsAllowDiscardedElision(array $callArgs): bool
    {
        return NoThrowCallElision::isAFamilyArgsCannotThrow($callArgs);
    }

    /**
     * Typed object (+ optional non-null bool-ish {@code $autoload}) — peer
     * {@see NoThrowCallElision::classHierarchyArgsCannotThrow}.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function classHierarchyArgsAllowDiscardedElision(array $callArgs): bool
    {
        return NoThrowCallElision::classHierarchyArgsCannotThrow($callArgs);
    }

    /**
     * Typed object only — peer {@see NoThrowCallElision::objectVarsMethodsArgsCannotThrow}.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function objectVarsMethodsArgsAllowDiscardedElision(array $callArgs): bool
    {
        return NoThrowCallElision::objectVarsMethodsArgsCannotThrow($callArgs);
    }
}
