<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Exists / introspect / version_compare no-throw proofs for
 * {@see NoThrowCallElision} (#36403 / #36386 / #36387).
 *
 * Convert / path-url / hash_equals / scalar-cast ArgsCannotThrow live in
 * {@see NoThrowCallElisionConvertAndScalarCastOps}. External call sites keep
 * using {@code NoThrowCallElision::…} (trait methods on the hub class).
 *
 * Used via {@code use NoThrowCallElisionExistsConvertAndIntrospectOps;} on
 * {@see NoThrowCallElision}.
 *
 * No new C ABI. php-src: Zend/zend_builtin_functions.c; ext/standard/
 * {info,basic_functions,array,versioning}.c; ext/spl.
 */
trait NoThrowCallElisionExistsConvertAndIntrospectOps
{
    /**
     * php-src {@code Zend/zend_builtin_functions.c} {@code function_exists} —
     * Z_PARAM_STR name; function table lookup only (no autoload). Soft-null
     * deprecates. Public for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureFunctionExistsBuiltin(string $nameLc): bool
    {
        return 'function_exists' === $nameLc;
    }

    /**
     * php-src {@code ext/standard/info.c} {@code extension_loaded} — Z_PARAM_STR
     * extension; registered-module table lookup. Soft-null deprecates. Public
     * for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureExtensionLoadedBuiltin(string $nameLc): bool
    {
        return 'extension_loaded' === $nameLc;
    }

    /**
     * php-src {@code ext/standard/basic_functions.c} {@code defined} — Z_PARAM_STR
     * constant name; constant table lookup only (no autoload). Soft-null
     * deprecates. Public for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureDefinedBuiltin(string $nameLc): bool
    {
        return 'defined' === $nameLc;
    }

    /**
     * php-src {@code Zend/zend_builtin_functions.c} {@code method_exists} —
     * object|string + Z_PARAM_STR method. Only the typed-object receiver is
     * proven no-throw / discardable (string class names autoload). Public for
     * {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureMethodExistsBuiltin(string $nameLc): bool
    {
        return 'method_exists' === $nameLc;
    }

    /**
     * php-src {@code Zend/zend_builtin_functions.c} {@code property_exists} —
     * object|string + Z_PARAM_STR property. Typed-object receiver only (string
     * class names autoload). Public for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPurePropertyExistsBuiltin(string $nameLc): bool
    {
        return 'property_exists' === $nameLc;
    }

    /**
     * php-src {@code ext/standard/array.c} {@code array_key_exists}/
     * {@code key_exists} — Z_PARAM_ZVAL key + Z_PARAM_ARRAY array. Soft-null
     * keys deprecate; object keys throw. Public for
     * {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureArrayKeyExistsBuiltin(string $nameLc): bool
    {
        return 'array_key_exists' === $nameLc || 'key_exists' === $nameLc;
    }

    /**
     * php-src {@code Zend/zend_builtin_functions.c} {@code class_exists} /
     * {@code interface_exists} / {@code trait_exists} / {@code enum_exists} —
     * Z_PARAM_STR name + optional Z_PARAM_BOOL autoload. Only proven when
     * {@code $autoload} is a compile-time false (default true runs autoload).
     * Public for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureClassExistsFamilyBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'class_exists':
            case 'interface_exists':
            case 'trait_exists':
            case 'enum_exists':
                return true;
            default:
                return false;
        }
    }

    /**
     * php-src {@code Zend/zend_builtin_functions.c} {@code get_class}/
     * {@code get_parent_class} and {@code ext/spl/php_spl.c}
     * {@code spl_object_id}/{@code spl_object_hash} — typed object operand
     * only (no autoload / no handlers). Public for
     * {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureObjectIntrospectBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'get_class':
            case 'get_parent_class':
            case 'spl_object_id':
            case 'spl_object_hash':
                return true;
            default:
                return false;
        }
    }

    /**
     * php-src {@code Zend/zend_builtin_functions.c} {@code is_a}/
     * {@code is_subclass_of} — typed object subject + Z_PARAM_STR class (+
     * optional Z_PARAM_BOOL allow_string). Object subjects never autoload;
     * string subjects stay out. Public for {@see DiscardedPureCallElision}
     * (#36386).
     */
    public static function isPureIsAFamilyBuiltin(string $nameLc): bool
    {
        return 'is_a' === $nameLc || 'is_subclass_of' === $nameLc;
    }

    /**
     * php-src {@code ext/standard/class.c} {@code class_parents},
     * {@code basic_functions.c} {@code class_implements},
     * {@code spl_functions.c} {@code class_uses} — typed object subject (+
     * optional Z_PARAM_BOOL autoload). Object subjects never autoload; string
     * subjects stay out. Public for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureClassHierarchyBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'class_parents':
            case 'class_implements':
            case 'class_uses':
                return true;
            default:
                return false;
        }
    }

    /**
     * php-src {@code Zend/zend_builtin_functions.c} {@code get_object_vars}/
     * {@code get_class_methods} and {@code ext/standard/var.c}
     * {@code get_mangled_object_vars} — typed object operand only (property /
     * method table read; no autoload / no user handlers). String
     * {@code get_class_methods} stays out (autoload). Public for
     * {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureObjectVarsMethodsBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'get_object_vars':
            case 'get_mangled_object_vars':
            case 'get_class_methods':
                return true;
            default:
                return false;
        }
    }

    /**
     * php-src {@code ext/standard/versioning.c} {@code version_compare}. Public
     * for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureVersionCompareBuiltin(string $nameLc): bool
    {
        return 'version_compare' === $nameLc;
    }

    /**
     * Exactly one typed / literal string — soft-null / {@code __toString} stay out.
     *
     * @param array<int, Variable> $callArgs
     */
    public static function functionExistsArgsCannotThrow(array $callArgs): bool
    {
        if (
            !isset($callArgs[0])
            || !$callArgs[0] instanceof Variable
            || isset($callArgs[1])
        ) {
            return false;
        }

        return self::stringParamBuiltinArgCannotThrow($callArgs[0]);
    }

    /**
     * Exactly one typed / literal string — soft-null / {@code __toString} stay out.
     *
     * @param array<int, Variable> $callArgs
     */
    public static function extensionLoadedArgsCannotThrow(array $callArgs): bool
    {
        return self::functionExistsArgsCannotThrow($callArgs);
    }

    /**
     * Exactly one typed / literal string — soft-null stays out (deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function definedArgsCannotThrow(array $callArgs): bool
    {
        return self::functionExistsArgsCannotThrow($callArgs);
    }

    /**
     * Typed object + typed / literal method string — soft-null method deprecates;
     * string class names / value-box receivers stay out (autoload / TypeError).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function methodExistsArgsCannotThrow(array $callArgs): bool
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

        return self::stringParamBuiltinArgCannotThrow($callArgs[1]);
    }

    /**
     * Typed object + typed / literal property string — peer
     * {@see methodExistsArgsCannotThrow}.
     *
     * @param array<int, Variable> $callArgs
     */
    public static function propertyExistsArgsCannotThrow(array $callArgs): bool
    {
        return self::methodExistsArgsCannotThrow($callArgs);
    }

    /**
     * Typed / literal class name + compile-time-false {@code $autoload}.
     * Soft-null name/autoload stay out (deprecate). Missing / true / dynamic
     * autoload stay out (spl_autoload side effects).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function classExistsFamilyArgsCannotThrow(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1])
            || isset($callArgs[2])
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
            || !self::stringParamBuiltinArgCannotThrow($callArgs[0])
        ) {
            return false;
        }

        return self::isCompileTimeFalseAutoloadArg($callArgs[1]);
    }

    /**
     * Exactly one typed object — zero-arg {@code get_class}/{@code get_parent_class}
     * deprecate / need scope; string {@code get_parent_class} autoloads; soft-null
     * / value-box stay out ({@code TypeError}).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function objectIntrospectArgsCannotThrow(array $callArgs): bool
    {
        if (
            !isset($callArgs[0])
            || isset($callArgs[1])
            || !$callArgs[0] instanceof Variable
        ) {
            return false;
        }

        return Variable::TYPE_OBJECT === $callArgs[0]->type;
    }

    /**
     * Typed object + typed / literal class string + optional non-null bool-ish
     * {@code $allow_string}. Soft-null class / allow_string deprecate; string /
     * value-box subjects stay out (autoload / handlers).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function isAFamilyArgsCannotThrow(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1])
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
            || Variable::TYPE_OBJECT !== $callArgs[0]->type
        ) {
            return false;
        }
        // Z_PARAM_STR class — soft-null deprecates (#29817); require typed /
        // literal string (not int/bool/null soft-coercion).
        $class = $callArgs[1];
        if ($class->isNullConstant || Variable::TYPE_NULL === $class->type) {
            return false;
        }
        if (
            null === JitStringArg::compileTimeLiteral($class)
            && Variable::TYPE_STRING !== $class->type
        ) {
            return false;
        }
        if (!isset($callArgs[2])) {
            return !isset($callArgs[3]);
        }
        if (!$callArgs[2] instanceof Variable || isset($callArgs[3])) {
            return false;
        }
        // Z_PARAM_BOOL — soft-null deprecates (#31339); objects/value-box may
        // __toString / handlers. Typed bool / long / compile-time 0|1 only.
        $allow = $callArgs[2];
        if ($allow->isNullConstant || Variable::TYPE_NULL === $allow->type) {
            return false;
        }
        if (Variable::TYPE_NATIVE_BOOL === $allow->type || Variable::TYPE_NATIVE_LONG === $allow->type) {
            return true;
        }

        return null !== $allow->compileTimeLong;
    }

    /**
     * Typed object (+ optional non-null bool-ish {@code $autoload}). Soft-null
     * autoload deprecates; string / value-box subjects stay out (autoload /
     * handlers). Object subjects never autoload — the class is already loaded.
     *
     * @param array<int, Variable> $callArgs
     */
    public static function classHierarchyArgsCannotThrow(array $callArgs): bool
    {
        if (
            !isset($callArgs[0])
            || !$callArgs[0] instanceof Variable
            || Variable::TYPE_OBJECT !== $callArgs[0]->type
        ) {
            return false;
        }
        if (!isset($callArgs[1])) {
            return !isset($callArgs[2]);
        }
        if (!$callArgs[1] instanceof Variable || isset($callArgs[2])) {
            return false;
        }
        // Z_PARAM_BOOL autoload — soft-null deprecates; objects/value-box may
        // __toString / handlers. Typed bool / long / compile-time 0|1 only.
        $autoload = $callArgs[1];
        if ($autoload->isNullConstant || Variable::TYPE_NULL === $autoload->type) {
            return false;
        }
        if (
            Variable::TYPE_NATIVE_BOOL === $autoload->type
            || Variable::TYPE_NATIVE_LONG === $autoload->type
        ) {
            return true;
        }

        return null !== $autoload->compileTimeLong;
    }

    /**
     * Exactly one typed object — peer {@see objectIntrospectArgsCannotThrow}.
     * Soft-null / string / value-box stay out ({@code TypeError} / autoload).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function objectVarsMethodsArgsCannotThrow(array $callArgs): bool
    {
        return self::objectIntrospectArgsCannotThrow($callArgs);
    }

    /**
     * Bool/long literals stamp {@see Variable::$compileTimeLong} (0/1). Soft-null
     * autoload deprecates and must stay live.
     */
    public static function isCompileTimeFalseAutoloadArg(Variable $arg): bool
    {
        if ($arg->isNullConstant || Variable::TYPE_NULL === $arg->type) {
            return false;
        }

        return null !== $arg->compileTimeLong && 0 === $arg->compileTimeLong;
    }

    /**
     * Typed array + non-null scalar key — soft-null keys deprecate; object /
     * value-box keys / non-array haystacks stay out.
     *
     * @param array<int, Variable> $callArgs
     */
    public static function arrayKeyExistsArgsCannotThrow(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1])
            || isset($callArgs[2])
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
        ) {
            return false;
        }
        if (!self::typedArrayArgCannotThrow($callArgs[1])) {
            return false;
        }
        $key = $callArgs[0];
        if ($key->isNullConstant || Variable::TYPE_NULL === $key->type) {
            return false;
        }
        if (Variable::TYPE_OBJECT === $key->type || Variable::TYPE_VALUE === $key->type) {
            return false;
        }
        if (self::stringParamBuiltinArgCannotThrow($key)) {
            return true;
        }

        return self::numericParamBuiltinArgCannotThrow($key);
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    public static function versionCompareArgsCannotThrow(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1])
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
            || !self::stringParamBuiltinArgCannotThrow($callArgs[0])
            || !self::stringParamBuiltinArgCannotThrow($callArgs[1])
        ) {
            return false;
        }
        if (!isset($callArgs[2])) {
            return true;
        }
        if (!$callArgs[2] instanceof Variable || isset($callArgs[3])) {
            return false;
        }
        // Null operator → two-arg form (no ValueError).
        if ($callArgs[2]->isNullConstant || Variable::TYPE_NULL === $callArgs[2]->type) {
            return true;
        }
        $op = JitStringArg::compileTimeLiteral($callArgs[2]);
        if (null === $op) {
            // Unknown typed string may be an invalid operator (ValueError).
            return false;
        }

        return self::isValidVersionCompareOperatorLiteral($op);
    }

    /**
     * php-src {@code versioning.c} operator set (lt/le/gt/ge/eq/ne + symbols).
     */
    public static function isValidVersionCompareOperatorLiteral(string $operator): bool
    {
        switch ($operator) {
            case '<':
            case 'lt':
            case '<=':
            case 'le':
            case '>':
            case 'gt':
            case '>=':
            case 'ge':
            case '==':
            case '=':
            case 'eq':
            case '!=':
            case '<>':
            case 'ne':
                return true;
            default:
                return false;
        }
    }

}
