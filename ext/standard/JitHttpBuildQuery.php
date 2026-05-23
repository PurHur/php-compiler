<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for http_build_query() via __compiler_http_build_query. */
final class JitHttpBuildQuery
{
    public static function build(
        Context $context,
        JITVariable $data,
        Value $prefix,
        Value $separator,
        Value $encoding
    ): Value {
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

        throw new \LogicException('http_build_query() argument #1 must be an array in this compiler build');
    }
}
