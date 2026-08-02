<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\Block;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\VM\SensitiveParamSupport;
use PHPLLVM\Value;

/**
 * On throw, cache a full Zend-shaped __toString with SensitiveParameter redaction (#26796).
 *
 * Compile-time constant string — no NestedJIT / property reads (those abort under thin AOT).
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
