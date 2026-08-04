<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\Block;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\VM\SensitiveParamSupport;
use PHPLLVM\Value;

/**
 * On throw, cache a full Zend-shaped __toString with SensitiveParameter redaction (#26796)
 * and seed PROP_TRACE for Exception::getTrace() under thin AOT (#27333 / #27549).
 *
 * Compile-time constant string + HT frame — no NestedJIT / VarFetchRuntime (thin AOT
 * cannot lower `string*` in VarFetchRuntime::ensureLinked).
 *
 * Honors {@see IniRuntime} thin EG(exception_ignore_args): when On, omit trace args and
 * print `f()` in __toString like Zend (#21998 / #27549).
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
        IniRuntime::ensureLinked($context);
        $ignoreArgs = IniRuntime::loadExceptionIgnoreArgs($context);
        $ignoreBb = BasicBlockHelper::append($context, 'exc_seed_ignore_args');
        $keepBb = BasicBlockHelper::append($context, 'exc_seed_keep_args');
        $doneBb = BasicBlockHelper::append($context, 'exc_seed_done');
        $context->builder->branchIf($ignoreArgs, $ignoreBb, $keepBb);

        $context->builder->positionAtEnd($ignoreBb);
        self::seedToString($context, $obj, $block, $decl, $cid, true);
        // Zend still emits file/line/function frames when ignore_args is On — only
        // omits the args key (#21998). Empty PROP_TRACE made getTrace()[0] SEGV (#27549).
        self::seedTrace($context, $obj, $block, $decl, $cid, false);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($keepBb);
        self::seedToString($context, $obj, $block, $decl, $cid, false);
        self::seedTrace($context, $obj, $block, $decl, $cid, true);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function seedToString(
        Context $context,
        Value $obj,
        Block $block,
        string $decl,
        int $cid,
        bool $ignoreArgs
    ): void {
        if (!$context->type->object->hasProperty($cid, ExceptionSupport::PROP_STRING)) {
            return;
        }
        $funcName = (string) $block->func->name;
        $parts = [];
        if (!$ignoreArgs) {
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
     * Seed Exception::$trace (#27333 / #27549).
     *
     * @param bool $includeArgs when false (ignore_args On), frame has no args key — Zend match
     */
    private static function seedTrace(
        Context $context,
        Value $obj,
        Block $block,
        string $decl,
        int $cid,
        bool $includeArgs
    ): void {
        if (!$context->type->object->hasProperty($cid, ExceptionSupport::PROP_TRACE)) {
            return;
        }
        $context->type->object->lookup(SensitiveParamSupport::CLASS_NAME);
        GetClassRuntime::ensureLinked($context);

        $sizeT = $context->getTypeFromString('size_t');
        $frameHt = HashTableHelper::alloc($context);
        $context->builder->call($context->lookupFunction('__hashtable__grow'), $frameHt, $sizeT->constInt(1, false));

        if ($includeArgs) {
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
            $argsKey = $context->builder->load($context->constantStringFromString('args'));
            HashTableHelper::setAtStringKey($context, $frameHt, $argsKey, $argsBoxed);
        }

        $funcKey = $context->builder->load($context->constantStringFromString('function'));
        $funcName = (string) $block->func->name;
        $funcStr = $context->builder->load($context->constantStringFromString($funcName));
        HashTableHelper::setAtStringKey(
            $context,
            $frameHt,
            $funcKey,
            new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $funcStr)
        );

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
