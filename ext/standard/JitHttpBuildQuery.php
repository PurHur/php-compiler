<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringHttpBuildQuery;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for http_build_query() via __compiler_http_build_query.
 *
 * php-src: ext/standard/http.c — Z_PARAM_ARRAY_OR_OBJECT + get_object_vars (#21950).
 */
final class JitHttpBuildQuery
{
    /**
     * Accept array|object. Objects become public-property hashtables (not SPL ArrayObject cast).
     */
    public static function normalizeDataArg(Context $context, JITVariable $arg): JITVariable
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type
            || (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY))
        ) {
            return $arg;
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return self::hashtableFromObject($context, $arg);
        }
        // Runtime-boxed / other: keep Zend 8.2 TypeError text ("array", not "array|object").
        JitArrayElem::requireArrayParam($context, $arg, 'http_build_query', 1, 'data');

        return $arg;
    }

    public static function build(
        Context $context,
        JITVariable $data,
        Value $prefix,
        Value $separator,
        Value $encoding
    ): Value {
        // Thin standalone AOT defers String_.implement — link the ABI body at the call site (#26869).
        StringHttpBuildQuery::ensureLinked($context);
        $ht = self::loadData($context, $data);

        return $context->builder->call(
            $context->lookupFunction('__compiler_http_build_query'),
            $ht,
            $prefix,
            $separator,
            $encoding
        );
    }

    private static function loadData(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return HashTableHelper::materializeNativeArrayForCall($context, $arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                JitValueBox::pointer($context, $arg->value)
            );
        }

        throw new \LogicException('http_build_query() argument #1 must be array|object in this compiler build');
    }

    private static function hashtableFromObject(Context $context, JITVariable $arg): JITVariable
    {
        $boxed = JitGetObjectVars::invoke($context, $arg, false);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $boxed
        );

        return new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht);
    }
}
