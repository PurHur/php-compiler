<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Class-id → name for get_class() / get_debug_type() (#10222, #24976, #26854).
 *
 * Emits an inline select-walk in the caller's insert block using the main module's
 * {@see Context::constantStringFromString} (initialized for thin AOT).
 *
 * A separate mid-function class-name ABI bridge (NestedJIT GetClassJitHelper or an
 * out-of-line helper) left the caller's insert block / string globals in a bad
 * state under standalone AOT, so `get_class($local)` and `get_class($e)` in
 * catch handlers aborted after printing a prefix (#26854). Peer: `$expr::class`
 * already inlines the same walk in {@see \PHPCompiler\JIT\ClassConstFetchHelperTrait}.
 *
 * php-src: ext/standard/basic_functions.c — get_class / zend_get_object_classname
 */
final class GetClassRuntime
{
    public static function ensureLinked(Context $context): void
    {
        self::seedThrowableClassNames($context);
    }

    public static function ensureDebugTypeLinked(Context $context): void
    {
        self::seedThrowableClassNames($context);
    }

    /**
     * @return Value {@see __string__*}
     */
    public static function emitClassNameFromId(Context $context, Value $classId): Value
    {
        self::seedThrowableClassNames($context);

        return self::emitSelectWalk($context, $classId);
    }

    /**
     * @return Value {@see __string__*}
     */
    public static function emitDebugTypeClassNameFromId(Context $context, Value $classId): Value
    {
        self::seedThrowableClassNames($context);
        // Display name: strip NUL provenance, keep Prefix@anonymous (#28840 / zend_compile.c).
        $name = self::emitSelectWalk($context, $classId);
        $result = $name;
        foreach ($context->type->object->allClassNamesById() as $id => $mapped) {
            $mappedStr = (string) $mapped;
            if (!str_contains($mappedStr, '@anonymous')) {
                continue;
            }
            $nul = strpos($mappedStr, "\0");
            $public = false !== $nul ? substr($mappedStr, 0, $nul) : $mappedStr;
            $anonLabel = $context->builder->load($context->constantStringFromString($public));
            $expected = $context->constantFromInteger((int) $id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $result = $context->builder->select($isId, $anonLabel, $result);
        }

        return $result;
    }

    /**
     * Test helper: historical generated-map shape (switch arms, not a static array).
     *
     * @param array<int, string> $namesById
     */
    public static function helperSourceForMap(array $namesById): string
    {
        $cases = '';
        ksort($namesById, \SORT_NUMERIC);
        foreach ($namesById as $id => $name) {
            $cases .= '            case '.(int) $id.': return '.var_export((string) $name, true).";\n";
        }

        return <<<PHP
<?php

declare(strict_types=1);

namespace PHPCompiler\\ext\\standard;

/** @generated per compile unit — {@see GetClassRuntime} */
final class GetClassJitHelper
{
    public static function classNameFromClassId(int \$classId): string
    {
        switch (\$classId) {
{$cases}            default:
                return '';
        }
    }

    public static function debugTypeClassNameFromClassId(int \$classId): string
    {
        \$name = self::classNameFromClassId(\$classId);
        if (str_contains(\$name, '@anonymous')) {
            \$nul = strpos(\$name, "\\0");
            return false !== \$nul ? substr(\$name, 0, \$nul) : \$name;
        }

        return \$name;
    }
}

PHP;
    }

    private static function seedThrowableClassNames(Context $context): void
    {
        $object = $context->type->object;
        // Catch handlers may lower get_class()/::class before the throw site registers
        // Error/Exception (#26854). Seed so the select-walk includes them.
        // Cross-function throw (incl. never-return callees) compiles the catch walk
        // before the callee's `new RuntimeException` registers the class (#27625).
        foreach ([
            'Throwable', 'Error', 'Exception', 'TypeError', 'ValueError',
            // EmptyIterator::current/key + peer SPL throws (#27582 / #24246).
            'BadMethodCallException', 'BadFunctionCallException', 'LogicException',
            // never-typed / cross-function throw → catch get_class (#27625).
            'RuntimeException',
            // json_decode/encode JSON_THROW_ON_ERROR compile-time fold (#27623).
            'JsonException',
            // Match / arithmetic engine errors — catch get_class before throw registers (#29747).
            'UnhandledMatchError', 'ArithmeticError', 'DivisionByZeroError',
            'ArgumentCountError', 'ParseError', 'CompileError', 'AssertionError',
        ] as $name) {
            $object->lookup($name);
        }
        // Trace redaction marker — catch get_class($e->getTrace()[0]['args'][0]) (#27333).
        $object->lookup(\PHPCompiler\VM\SensitiveParamSupport::CLASS_NAME);
    }

    private static function emitSelectWalk(Context $context, Value $classId): Value
    {
        $result = $context->builder->load($context->constantStringFromString(''));
        foreach ($context->type->object->allClassNamesById() as $id => $name) {
            $expected = $context->constantFromInteger((int) $id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $candidate = $context->builder->load($context->constantStringFromString((string) $name));
            $result = $context->builder->select($isId, $candidate, $result);
        }

        return $result;
    }
}
