<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\Block;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\VM\SensitiveParamSupport;
use PHPLLVM\Value;

/**
 * On throw, cache a full Zend-shaped __toString with SensitiveParameter redaction (#26796)
 * and seed PROP_TRACE for Exception::getTrace() under thin AOT (#27333).
 *
 * Compile-time constant string + HT frame — no NestedJIT / VarFetchRuntime (thin AOT
 * cannot lower `string*` in VarFetchRuntime::ensureLinked).
 */
final class ExceptionThrowToStringSeed
{
    public static function seed(Context $context, Value $obj, Block $block): void
    {
        if (null === $block->func || $block->isMainScript()) {
            return;
        }
        if ([] === $block->paramSensitive) {
            return;
        }
        $decl = self::declaringClass($context);
        try {
            $cid = $context->type->object->lookup($decl);
        } catch (\Throwable) {
            return;
        }
        self::seedToString($context, $obj, $block, $decl, $cid);
        self::seedTrace($context, $obj, $block, $decl, $cid);
    }

    private static function seedToString(
        Context $context,
        Value $obj,
        Block $block,
        string $decl,
        int $cid
    ): void {
        if (!$context->type->object->hasProperty($cid, ExceptionSupport::PROP_STRING)) {
            return;
        }
        $funcName = (string) $block->func->name;
        $parts = [];
        foreach (array_keys($block->paramNames) as $paramIdx) {
            $name = $block->paramNames[$paramIdx] ?? '';
            if ('this' === $name) {
                continue;
            }
            if (SensitiveParamSupport::compileTimeParamIsSensitive($block->paramSensitive, (int) $paramIdx)) {
                $parts[] = 'Object('.SensitiveParamSupport::CLASS_NAME.')';
            } else {
                $parts[] = '...';
            }
        }
        $file = $context->jitAotEntryScriptPath;
        if ('' === $file) {
            $file = 'Unknown';
        }
        $line = max(0, $context->callSiteLine);
        $argsCsv = implode(', ', $parts);
        $body = $decl.': boom in '.$file.':'.$line."\n"
            .'Stack trace:'."\n"
            .'#0 '.$file.'('.$line.'): '.$funcName.'('.$argsCsv.")\n"
            .'#1 {main}';
        $str = $context->builder->load($context->constantStringFromString($body));
        $strVar = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $str);
        $context->type->object->storeInstanceProperty(
            $obj,
            $decl,
            ExceptionSupport::PROP_STRING,
            $strVar
        );
    }

    /**
     * Seed Exception::$trace with a SensitiveParameterValue-wrapped args frame (#27333).
     *
     * Avoids {@see \PHPCompiler\JIT\SensitiveParamHelper::buildArgsArray} (VarFetchRuntime
     * `string*` aborts under thin AOT). Nested HTs are value-boxed before packing.
     */
    private static function seedTrace(
        Context $context,
        Value $obj,
        Block $block,
        string $decl,
        int $cid
    ): void {
        if (!$context->type->object->hasProperty($cid, ExceptionSupport::PROP_TRACE)) {
            return;
        }
        $context->type->object->lookup(SensitiveParamSupport::CLASS_NAME);
        GetClassRuntime::ensureLinked($context);

        $sizeT = $context->getTypeFromString('size_t');
        $argsHt = HashTableHelper::alloc($context);
        $context->builder->call($context->lookupFunction('__hashtable__grow'), $argsHt, $sizeT->constInt(1, false));
        $index = 0;
        foreach (array_keys($block->paramNames) as $paramIdx) {
            $paramName = $block->paramNames[$paramIdx] ?? '';
            if ('this' === $paramName) {
                continue;
            }
            $slot = $context->constantFromInteger($index, 'size_t');
            ++$index;
            if (SensitiveParamSupport::compileTimeParamIsSensitive($block->paramSensitive, (int) $paramIdx)) {
                HashTableHelper::setAtIndex(
                    $context,
                    $argsHt,
                    $slot,
                    SensitiveParamRuntime::wrapValue($context, null)
                );
            }
        }
        $argsBoxed = HashTableHelper::boxedArrayFromHashtable(
            $context,
            new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $argsHt)
        );

        $frameHt = HashTableHelper::alloc($context);
        $context->builder->call($context->lookupFunction('__hashtable__grow'), $frameHt, $sizeT->constInt(1, false));
        $argsKey = $context->builder->load($context->constantStringFromString('args'));
        HashTableHelper::setAtStringKey($context, $frameHt, $argsKey, $argsBoxed);
        $frameBoxed = HashTableHelper::boxedArrayFromHashtable(
            $context,
            new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $frameHt)
        );

        $traceHt = HashTableHelper::alloc($context);
        $context->builder->call($context->lookupFunction('__hashtable__grow'), $traceHt, $sizeT->constInt(1, false));
        HashTableHelper::setAtIndex(
            $context,
            $traceHt,
            $context->constantFromInteger(0, 'size_t'),
            $frameBoxed
        );
        $context->type->object->storeInstanceProperty(
            $obj,
            $decl,
            ExceptionSupport::PROP_TRACE,
            new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $traceHt)
        );
    }

    private static function declaringClass(Context $context): string
    {
        foreach (['Exception', 'Error'] as $candidate) {
            try {
                $cid = $context->type->object->lookup($candidate);
            } catch (\Throwable) {
                continue;
            }
            if ($context->type->object->hasProperty($cid, ExceptionSupport::PROP_MESSAGE)) {
                return $candidate;
            }
        }

        return 'Exception';
    }
}
