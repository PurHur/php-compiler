<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Format captured exception trace arrays (Zend zend_exceptions.c getTraceAsString).
 */
final class ExceptionTraceFormat
{
    public static function asString(Variable $traceVar): string
    {
        $traceVar = $traceVar->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $traceVar->type) {
            return '#0 {main}';
        }
        $ht = $traceVar->toArray();
        $count = $ht->getNumElements();
        if (0 === $count) {
            return '#0 {main}';
        }
        $lines = [];
        $index = 0;
        foreach ($ht->iterate(true) as $frameVar) {
            $lines[] = self::formatFrame($index, $frameVar);
            ++$index;
        }
        if ([] === $lines || !str_ends_with($lines[\count($lines) - 1], '{main}')) {
            $lines[] = "#{$index} {main}";
        }

        return implode("\n", $lines);
    }

    private static function formatFrame(int $index, Variable $frameVar): string
    {
        $frameVar = $frameVar->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $frameVar->type) {
            return "#{$index} {main}";
        }
        $ht = $frameVar->toArray();
        $function = self::readStringKey($ht, 'function');
        if ('{main}' === $function) {
            return "#{$index} {main}";
        }
        $file = self::readStringKey($ht, 'file');
        $line = self::readIntKey($ht, 'line');
        $call = self::formatCall($function, $ht);
        if ('' !== $file) {
            return "#{$index} {$file}({$line}): {$call}";
        }

        return "#{$index} {$call}";
    }

    private static function formatCall(string $function, HashTable $ht): string
    {
        $class = self::readStringKey($ht, 'class');
        $type = self::readStringKey($ht, 'type');
        $prefix = '';
        if ('' !== $class) {
            $prefix = $class.('' !== $type ? $type : '->');
        }
        $argsKey = new Variable(Variable::TYPE_STRING);
        $argsKey->string('args');
        if (!$ht->keyExists($argsKey)) {
            if ('' === $function) {
                return '{main}';
            }

            return $prefix.$function.'()';
        }
        $argsVar = $ht->findVariable($argsKey, false);
        if (null === $argsVar) {
            return $prefix.$function.'()';
        }
        $argsVar = $argsVar->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $argsVar->type) {
            return $prefix.$function.'()';
        }
        $parts = [];
        foreach ($argsVar->toArray()->iterate(true) as $arg) {
            $parts[] = SensitiveParamSupport::formatTraceArg($arg);
        }

        return $prefix.$function.'('.implode(', ', $parts).')';
    }

    private static function readStringKey(HashTable $ht, string $key): string
    {
        $keyVar = new Variable(Variable::TYPE_STRING);
        $keyVar->string($key);
        if (!$ht->keyExists($keyVar)) {
            return '';
        }
        $var = $ht->findVariable($keyVar, false);
        if (null === $var) {
            return '';
        }
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING !== $var->type) {
            return '';
        }

        return $var->toString();
    }

    private static function readIntKey(HashTable $ht, string $key): int
    {
        $keyVar = new Variable(Variable::TYPE_STRING);
        $keyVar->string($key);
        if (!$ht->keyExists($keyVar)) {
            return 0;
        }
        $var = $ht->findVariable($keyVar, false);
        if (null === $var) {
            return 0;
        }
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type) {
            return 0;
        }

        return $var->toInt();
    }
}
