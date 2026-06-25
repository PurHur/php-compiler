<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for get_html_translation_table() (#3637). */
final class JitGetHtmlTranslationTable
{
    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $table = self::compileTimeInt($context, $args[0] ?? null, HTML_SPECIALCHARS, 'table');
        $flags = self::compileTimeInt($context, $args[1] ?? null, ENT_QUOTES | ENT_SUBSTITUTE, 'flags');
        $encoding = 'UTF-8';
        if (isset($args[2])) {
            $literal = JitStringArg::compileTimeLiteral($args[2]);
            if (null === $literal) {
                throw new \LogicException(
                    'get_html_translation_table() JIT only supports compile-time encoding in this compiler build'
                );
            }
            $encoding = $literal;
        }

        $entries = [];
        $phpHt = VmString::getHtmlTranslationTable($table, $flags, $encoding);
        foreach ($phpHt->iterateKeyed(true) as [$key, $value]) {
            $entries[$key->toString()] = $value->toString();
        }

        return self::buildArray($context, $entries);
    }

    /** @param array<string, string> $parts */
    private static function buildArray(Context $context, array $parts): Value
    {
        $ht = HashTableHelper::alloc($context);
        foreach ($parts as $key => $value) {
            $keyStr = $context->builder->load($context->constantStringFromString((string) $key));
            $valStr = $context->builder->load($context->constantStringFromString((string) $value));
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyString'),
                $ht,
                $keyStr,
                $valStr
            );
        }

        return $ht;
    }

    private static function compileTimeInt(
        Context $context,
        ?JITVariable $arg,
        int $default,
        string $label
    ): int {
        if (null === $arg) {
            return $default;
        }
        $constName = $arg->compileTimeConstantName ?? null;
        if (null !== $constName) {
            $lookup = strtolower($constName);
            if (isset(StdlibConstants::CORE_INT_BY_NAME[$lookup])) {
                return StdlibConstants::CORE_INT_BY_NAME[$lookup];
            }
            $phpVar = $context->runtime->vmContext->constantFetch($constName);
            if (null !== $phpVar && \PHPCompiler\VM\Variable::TYPE_INTEGER === $phpVar->type) {
                return $phpVar->toInt();
            }
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type
            && JITVariable::KIND_VALUE === $arg->kind
        ) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($arg->value->value)) {
                return (int) $lib->LLVMConstIntGetSExtValue($arg->value->value);
            }
        }

        throw new \LogicException(
            "get_html_translation_table() {$label} must be a compile-time integer in this compiler build"
        );
    }
}
