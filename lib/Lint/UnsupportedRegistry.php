<?php

declare(strict_types=1);

namespace PHPCompiler\Lint;

/**
 * Maps unsupported CFG kinds and known limitation feature ids to GitHub issues.
 *
 * @see docs/unsupported-syntax.md
 * @see https://github.com/PurHur/php-compiler/issues/36396
 */
final class UnsupportedRegistry
{
    public const ISSUE_URL_BASE = 'https://github.com/PurHur/php-compiler/issues/';

    /** @var array<string, int> */
    private const KIND_TO_ISSUE = [
        'Stmt_Try' => 57,
        'Stmt_TryCatch' => 57,
        'Stmt_Catch' => 57,
        'Stmt_Finally' => 57,
    ];

    /**
     * Catalogued known limitations (compile/JIT rejection sites).
     *
     * Each entry must include feature, matrixRow, issue, and alternative.
     *
     * @var array<string, array{feature: string, matrixRow: string, issue: int, alternative: string}>
     */
    private const FEATURES = [
        'range-non-int-endpoints' => [
            'feature' => 'range() start/end that are not int, float, or single-char string',
            'matrixRow' => 'docs/capabilities.md#range',
            'issue' => 4258,
            'alternative' => 'use integer, float, or single-character string bounds (php-src ext/standard/array.c)',
        ],
        'range-float-path-operands' => [
            'feature' => 'range() float path with non-numeric operands',
            'matrixRow' => 'docs/capabilities.md#range',
            'issue' => 27158,
            'alternative' => 'pass native int/float bounds (or null) so the float path can lower',
        ],
        'jit-unsupported-vm-constant' => [
            'feature' => 'Unsupported compile-time constant for JIT',
            'matrixRow' => 'docs/capabilities-syntax.md#literals',
            'issue' => 36396,
            'alternative' => 'fold the constant to int/float/string/array/null/enum-case before JIT lowering',
        ],
        'array-unique-flags' => [
            'feature' => 'array_unique() flags outside SORT_REGULAR/STRING/NUMERIC',
            'matrixRow' => 'docs/capabilities.md#array_unique',
            'issue' => 27066,
            'alternative' => 'use SORT_REGULAR, SORT_STRING, or SORT_NUMERIC (php-src php_array_unique)',
        ],
        'array-callback-deferred' => [
            'feature' => 'array_* callback form not lowerable for JIT/AOT',
            'matrixRow' => 'docs/capabilities.md#array_map',
            'issue' => 3073,
            'alternative' => 'use a string builtin/user-function name or a closure; array/invokable callables are deferred',
        ],
        'set-error-handler-callback' => [
            'feature' => 'set_error_handler() callback form not lowerable for JIT/AOT',
            'matrixRow' => 'docs/capabilities.md#set_error_handler',
            'issue' => 1379,
            'alternative' => 'use a compile-time string function name or a closure; array/invokable callables are deferred',
        ],
        'set-exception-handler-callback' => [
            'feature' => 'set_exception_handler() callback form not lowerable for JIT/AOT',
            'matrixRow' => 'docs/capabilities.md#set_exception_handler',
            'issue' => 1379,
            'alternative' => 'use null or a compile-time string function name',
        ],
        'exit-status-type' => [
            'feature' => 'exit()/die() status type outside string|int',
            'matrixRow' => 'docs/capabilities-syntax.md#exit',
            'issue' => 22492,
            'alternative' => 'pass a string message or integer status (PHP 8.4+ rejects array; see Zend/zend_compile.c)',
        ],
        'try-catch' => [
            'feature' => 'try/catch/finally',
            'matrixRow' => 'docs/unsupported-syntax.md#try-catch',
            'issue' => 57,
            'alternative' => 'handle errors without try/catch, or run under VM until AOT unwind lands',
        ],
        'range-non-int-step' => [
            'feature' => 'range() step outside int/float/bool/boxed numeric',
            'matrixRow' => 'docs/capabilities.md#range',
            'issue' => 4258,
            'alternative' => 'pass an int or float step (php-src ext/standard/array.c Z_PARAM_NUMBER)',
        ],
        'array-map-multi-callback' => [
            'feature' => 'array_map() with multiple arrays and a non-lowerable callback',
            'matrixRow' => 'docs/capabilities.md#array_map',
            'issue' => 4539,
            'alternative' => 'use null, a closure, or a compile-time string builtin callback (php-src ext/standard/array.c)',
        ],
        'array-map-invokable-deferred' => [
            'feature' => 'array_map() invokable-object callback',
            'matrixRow' => 'docs/capabilities.md#array_map',
            'issue' => 16228,
            'alternative' => 'use null, a compile-time string builtin, a closure/arrow, or a static/bound [Class|$this, method] callable',
        ],
        'array-walk-string-callback' => [
            'feature' => 'array_walk() string callback not registered in this compile unit',
            'matrixRow' => 'docs/capabilities.md#array_walk',
            'issue' => 33728,
            'alternative' => 'use a stdlib builtin name or a user function defined in the same compile unit',
        ],
        'array-walk-recursive-userdata' => [
            'feature' => 'array_walk_recursive() with userdata under JIT/AOT',
            'matrixRow' => 'docs/capabilities.md#array_walk_recursive',
            'issue' => 4913,
            'alternative' => 'omit userdata, or close over state in a 2-arg closure (php-src php_array_walk)',
        ],
        'substr-replace-array-with-array' => [
            'feature' => 'substr_replace() array $string with array $replace',
            'matrixRow' => 'docs/capabilities.md#substr_replace',
            'issue' => 29309,
            'alternative' => 'pass a scalar string $replace, or replace one string at a time (php-src ext/standard/string.c)',
        ],
        'substr-replace-array-element-type' => [
            'feature' => 'substr_replace() array $replace element not string-coercible at compile time',
            'matrixRow' => 'docs/capabilities.md#substr_replace',
            'issue' => 29309,
            'alternative' => 'use string/int/float/bool/null elements in a compile-time array literal',
        ],
        'substr-replace-runtime-array-replace' => [
            'feature' => 'substr_replace() runtime array $replace',
            'matrixRow' => 'docs/capabilities.md#substr_replace',
            'issue' => 29309,
            'alternative' => 'pass a scalar string $replace, or a compile-time array literal',
        ],
        'simplexml-load-string-jit' => [
            'feature' => 'simplexml_load_string() outside user-script AOT fold path',
            'matrixRow' => 'docs/capabilities.md#simplexml_load_string',
            'issue' => 26863,
            'alternative' => 'use a compile-time XML literal under `phpc build`, or parse under VM',
        ],
        'array-multisort-arity' => [
            'feature' => 'array_multisort() with fewer than two array arguments under JIT/AOT',
            'matrixRow' => 'docs/capabilities.md#array_multisort',
            'issue' => 26908,
            'alternative' => 'pass at least two arrays (php-src php_array_multisort)',
        ],
        'path-string-as-array' => [
            'feature' => 'using a string path as an array container under JIT/AOT',
            'matrixRow' => 'docs/capabilities-syntax.md#arrays',
            'issue' => 36396,
            'alternative' => 'pass a hashtable/array variable, not a filesystem path string',
        ],
        'hashtable-index-type' => [
            'feature' => 'hashtable offset that is not int or string under JIT/AOT',
            'matrixRow' => 'docs/capabilities-syntax.md#arrays',
            'issue' => 29567,
            'alternative' => 'use integer or string keys (php-src zend_hash / Zend/zend_execute.c)',
        ],
        'isset-object-array-offset' => [
            'feature' => 'isset() array offset on object containers other than SplObjectStorage/typed properties',
            'matrixRow' => 'docs/capabilities-syntax.md#isset',
            'issue' => 10170,
            'alternative' => 'use SplObjectStorage, a typed object property array, or a hashtable variable',
        ],
        'array-key-exists-key-type' => [
            'feature' => 'array_key_exists()/key_exists() key type not lowerable for JIT/AOT',
            'matrixRow' => 'docs/capabilities.md#array_key_exists',
            'issue' => 13735,
            'alternative' => 'use int/string/null/bool/float keys, or a value-boxed key (php-src ext/standard/array.c)',
        ],
        'array-key-exists-native-key-type' => [
            'feature' => 'array_key_exists()/key_exists() non-integer key on a native array',
            'matrixRow' => 'docs/capabilities.md#array_key_exists',
            'issue' => 13735,
            'alternative' => 'use an integer key, or assign the array to a hashtable variable first',
        ],
        'jit-string-arg' => [
            'feature' => 'JIT/AOT string argument that cannot be lowered to __string__*',
            'matrixRow' => 'docs/capabilities-syntax.md#strings',
            'issue' => 816,
            'alternative' => 'pass a string, concat path, boxed string value, or hashtable-backed string (php-src Z_PARAM_STR)',
        ],
        'jit-long-arg' => [
            'feature' => 'JIT/AOT integer argument that cannot be lowered to i64',
            'matrixRow' => 'docs/capabilities-syntax.md#integers',
            'issue' => 36396,
            'alternative' => 'pass int/float/bool/null/string/object or a boxed numeric value (php-src Z_PARAM_LONG)',
        ],
        'unset-offset-container' => [
            'feature' => 'unset() offset on a container that is not an array or object',
            'matrixRow' => 'docs/capabilities-syntax.md#unset',
            'issue' => 30065,
            'alternative' => 'unset on a hashtable/array or object property (php-src ZEND_UNSET_DIM / ZEND_UNSET_OBJ)',
        ],
        'closure-from-callable-literal' => [
            'feature' => 'Closure::fromCallable() without a compile-time string callable',
            'matrixRow' => 'docs/capabilities.md#closure-fromcallable',
            'issue' => 26788,
            'alternative' => 'pass a compile-time string function/Class::method name (php-src Zend/zend_closures.c)',
        ],
        'reflection-static-property-name-literal' => [
            'feature' => 'ReflectionClass::{get,set}StaticPropertyValue() name not a string literal',
            'matrixRow' => 'docs/capabilities.md#reflectionclass',
            'issue' => 34125,
            'alternative' => 'pass a compile-time string property name (php-src ext/reflection/php_reflection.c)',
        ],
        'reflection-constant-name-literal' => [
            'feature' => 'ReflectionClass::getConstant() name not a string literal',
            'matrixRow' => 'docs/capabilities.md#reflectionclass',
            'issue' => 34093,
            'alternative' => 'pass a compile-time string constant name (php-src zim_ReflectionClass_getConstant)',
        ],
        'reflection-constants-filter' => [
            'feature' => 'ReflectionClass::getConstants() filter not a compile-time int',
            'matrixRow' => 'docs/capabilities.md#reflectionclass',
            'issue' => 34093,
            'alternative' => 'omit the filter or pass a compile-time int (php-src zim_ReflectionClass_getConstants)',
        ],
        'date-period-iso-construct' => [
            'feature' => 'DatePeriod::__construct(string $isostr) under JIT/AOT',
            'matrixRow' => 'docs/capabilities.md#dateperiod',
            'issue' => 26772,
            'alternative' => 'use DatePeriod::createFromISO8601String() or the end-date/recurrence constructors (php-src date_period_construct)',
        ],
        'usort-callback-deferred' => [
            'feature' => 'usort()/uksort()/uasort() callback form not lowerable for JIT/AOT',
            'matrixRow' => 'docs/capabilities.md#usort',
            'issue' => 23550,
            'alternative' => 'use compile-time strcmp or a closure/arrow comparator; array/invokable callables are deferred',
        ],
        'array-reduce-callback-deferred' => [
            'feature' => 'array_reduce() callback form not lowerable for JIT/AOT',
            'matrixRow' => 'docs/capabilities.md#array_reduce',
            'issue' => 142,
            'alternative' => 'use a compile-time string user-function name or a closure/arrow; array callables are deferred',
        ],
        'spl-autoload-callback-deferred' => [
            'feature' => 'spl_autoload_register() callback form not lowerable for JIT/AOT',
            'matrixRow' => 'docs/capabilities.md#spl_autoload_register',
            'issue' => 1776,
            'alternative' => 'use a compile-time function name, Class::method, or closure (#4744); array/invokable callables are deferred',
        ],
        'preg-replace-callback-deferred' => [
            'feature' => 'preg_replace_callback() callback form not lowerable for JIT/AOT',
            'matrixRow' => 'docs/capabilities.md#preg_replace_callback',
            'issue' => 1177,
            'alternative' => 'use a compile-time string function name or a closure; array/invokable callables are deferred (#142, #36382)',
        ],
        'array-filter-callback-deferred' => [
            'feature' => 'array_filter() callback form not lowerable for JIT/AOT',
            'matrixRow' => 'docs/capabilities.md#array_filter',
            'issue' => 32672,
            'alternative' => 'use null or a closure/arrow with ARRAY_FILTER_USE_VALUE; string/array callables are deferred',
        ],
        'attribute-non-constant-arg' => [
            'feature' => 'Attribute constructor arguments must be compile-time constant expressions',
            'matrixRow' => 'docs/capabilities-syntax.md#attributes',
            'issue' => 3206,
            'alternative' => 'use literals, consts, or constant expressions (php-src zend_compile_attribute / zend_ast_evaluate)',
        ],
        'backed-enum-from-jit' => [
            'feature' => 'BackedEnum::from()/tryFrom() via Internal::call under JIT/AOT',
            'matrixRow' => 'docs/capabilities-syntax.md#enums',
            'issue' => 3114,
            'alternative' => 'use the VM path, or call from()/tryFrom() so JIT lowers via EnumSupport lookup (php-src Zend/zend_enum.c)',
        ],
        'new-first-class-callable-jit' => [
            'feature' => 'new Class(...) first-class callable under JIT/AOT',
            'matrixRow' => 'docs/capabilities-syntax.md#first-class-callable',
            'issue' => 9767,
            'alternative' => 'construct with new Class(...args) directly, or run under VM (php-src zend_compile.c Expr_New first-class callable)',
        ],
        'isset-static-property-dynamic-name' => [
            'feature' => 'isset(Class::$prop) with a dynamic property name under JIT/AOT',
            'matrixRow' => 'docs/capabilities-syntax.md#isset',
            'issue' => 10170,
            'alternative' => 'use a compile-time string property name (php-src ZEND_ISSET_ISEMPTY_STATIC_PROP)',
        ],
        'empty-static-property-dynamic-name' => [
            'feature' => 'empty(Class::$prop) with a dynamic property name under JIT/AOT',
            'matrixRow' => 'docs/capabilities-syntax.md#empty',
            'issue' => 23983,
            'alternative' => 'use a compile-time string property name (php-src ZEND_ISSET_ISEMPTY_STATIC_PROP)',
        ],
        'yield-script-scope-aot' => [
            'feature' => 'yield in the main script under AOT',
            'matrixRow' => 'docs/capabilities-syntax.md#generators',
            'issue' => 3115,
            'alternative' => 'move yield into a generator function; script-scope yield remains deferred (docs/generators-jit-aot.md)',
        ],
        'reflection-class-name-literal' => [
            'feature' => 'Reflection/introspection class name not a compile-time string literal',
            'matrixRow' => 'docs/capabilities.md#reflectionclass',
            'issue' => 1214,
            'alternative' => 'pass a compile-time string class name (php-src ext/reflection/php_reflection.c)',
        ],
        'get-class-object-arg' => [
            'feature' => 'get_class() argument that is not an object or boxed value under JIT/AOT',
            'matrixRow' => 'docs/capabilities.md#get_class',
            'issue' => 1214,
            'alternative' => 'pass an object or omit the argument for the current class (php-src ext/standard/basic_functions.c)',
        ],
        'get-debug-type-object-arg' => [
            'feature' => 'get_debug_type() argument that is not an object or boxed value under JIT/AOT',
            'matrixRow' => 'docs/capabilities.md#get_debug_type',
            'issue' => 1214,
            'alternative' => 'pass an object or boxed value (php-src Zend/zend_builtin_functions.c zend_get_debug_type)',
        ],
        'spl-object-storage-key-type' => [
            'feature' => 'SplObjectStorage offset key that is not an object under JIT/AOT',
            'matrixRow' => 'docs/capabilities.md#splobjectstorage-offsetset',
            'issue' => 601,
            'alternative' => 'use object keys only (php-src ext/spl/spl_observer.c)',
        ],
        'foreach-object-container' => [
            'feature' => 'foreach over object containers other than SplObjectStorage/ArrayIterator/WeakMap under JIT/AOT',
            'matrixRow' => 'docs/capabilities-syntax.md#foreach',
            'issue' => 3331,
            'alternative' => 'foreach a hashtable/array, SplObjectStorage, ArrayIterator, or WeakMap (php-src zend_fe_reset_ex)',
        ],
        'foreach-generator-value' => [
            'feature' => 'foreach over a non-Generator value in generator iterator lowering',
            'matrixRow' => 'docs/capabilities-syntax.md#generators',
            'issue' => 167,
            'alternative' => 'foreach a Generator returned from a yield function (php-src Zend/zend_generators.c)',
        ],
    ];

    /**
     * @return array{feature: string, matrixRow: string, issue: int, alternative: string}
     */
    public static function feature(string $featureId): array
    {
        if (!isset(self::FEATURES[$featureId])) {
            throw new \InvalidArgumentException('Unknown unsupported feature id: '.$featureId);
        }

        return self::FEATURES[$featureId];
    }

    /**
     * @return array<string, array{feature: string, matrixRow: string, issue: int, alternative: string}>
     */
    public static function knownFeatures(): array
    {
        return self::FEATURES;
    }

    public static function trackingIssueForKind(string $kind): ?int
    {
        if (isset(self::KIND_TO_ISSUE[$kind])) {
            return self::KIND_TO_ISSUE[$kind];
        }
        foreach (self::KIND_TO_ISSUE as $prefix => $issue) {
            if (str_starts_with($kind, $prefix)) {
                return $issue;
            }
        }

        return null;
    }

    public static function issueUrl(int $issue): string
    {
        return self::ISSUE_URL_BASE.$issue;
    }

    /**
     * Explain text for a lint kind when a catalogued feature or try/catch map exists.
     */
    public static function explainForKind(string $kind): ?string
    {
        if (str_starts_with($kind, 'Stmt_Try')
            || str_starts_with($kind, 'Stmt_Catch')
            || str_starts_with($kind, 'Stmt_Finally')
        ) {
            $row = self::FEATURES['try-catch'];

            return UnsupportedFeature::format(
                $row['feature'],
                $row['matrixRow'],
                $row['issue'],
                $row['alternative']
            );
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    public static function knownKinds(): array
    {
        return self::KIND_TO_ISSUE;
    }

    /**
     * @param list<\PHPCompiler\Lint\Issue> $issues
     *
     * @return array<string, list<\PHPCompiler\Lint\Issue>> absolute file path => issues
     */
    public static function groupIssuesByFile(array $issues): array
    {
        $byFile = [];
        foreach ($issues as $issue) {
            $byFile[$issue->file][] = $issue;
        }
        ksort($byFile);

        return $byFile;
    }

    /**
     * @param list<\PHPCompiler\Lint\Issue> $issues
     *
     * @return list<string> unique unsupported CFG kinds
     */
    public static function uniqueKinds(array $issues): array
    {
        $kinds = [];
        foreach ($issues as $issue) {
            $kinds[$issue->kind] = true;
        }
        $list = array_keys($kinds);
        sort($list);

        return $list;
    }
}
