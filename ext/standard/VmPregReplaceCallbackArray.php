<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * VM preg_replace_callback_array() — sequential preg_replace_callback per pattern (#3568, #25735).
 *
 * php-src: ext/pcre/php_pcre.c — PHP_FUNCTION(preg_replace_callback_array)
 */
final class VmPregReplaceCallbackArray
{
    /**
     * @return string|array<int|string, string>|false
     */
    public static function invoke(
        Context $vmContext,
        HashTable $patterns,
        Variable $subjectVar,
        int $limit = -1,
        ?int &$count = null,
        int $flags = 0,
        ?Frame $scopeFrame = null
    ) {
        $pairs = self::patternCallbackPairs($patterns);
        if ([] === $pairs) {
            return '';
        }

        if (Variable::TYPE_STRING === $subjectVar->type) {
            $subject = $subjectVar->toString();
            $totalCount = 0;
            foreach ($pairs as [$pattern, $callback]) {
                $partial = 0;
                $result = VmPregReplaceCallback::invoke(
                    $vmContext,
                    $pattern,
                    $callback,
                    $subject,
                    $limit,
                    $partial,
                    $flags,
                    $scopeFrame,
                    'preg_replace_callback_array'
                );
                if (false === $result) {
                    return false;
                }
                $subject = $result;
                $totalCount += $partial;
            }
            if (null !== $count) {
                $count = $totalCount;
            }

            return $subject;
        }

        if (Variable::TYPE_ARRAY !== $subjectVar->type) {
            throw new \LogicException(
                'preg_replace_callback_array() subject must be string or array in this compiler build'
            );
        }

        $totalCount = 0;
        $out = new HashTable();
        foreach ($subjectVar->toArray()->iterateKeyed(true) as [$key, $value]) {
            if (Variable::TYPE_STRING !== $value->type) {
                throw new \TypeError(
                    'preg_replace_callback_array(): Argument #2 ($subject) must be of type array|string, '
                    .self::typeLabel($value).' given'
                );
            }
            $elem = new Variable();
            $elem->string($value->toString());
            $elemCount = 0;
            $result = self::invoke(
                $vmContext,
                $patterns,
                $elem,
                $limit,
                $elemCount,
                $flags,
                $scopeFrame
            );
            if (false === $result) {
                return false;
            }
            $totalCount += $elemCount;
            $keyVar = new Variable();
            if (Variable::TYPE_INTEGER === $key->type) {
                $keyVar->int($key->toInt());
            } else {
                $keyVar->string($key->toString());
            }
            $outVal = new Variable();
            $outVal->string((string) $result);
            array_map::appendKeyedCopy($out, $keyVar, $outVal);
        }
        if (null !== $count) {
            $count = $totalCount;
        }

        return $out;
    }

    /**
     * @return list<array{0: string, 1: Variable}>
     */
    public static function patternCallbackPairs(HashTable $patterns): array
    {
        $pairs = [];
        foreach ($patterns->iterateKeyed(true) as [$key, $value]) {
            $key = $key->resolveIndirect();
            $value = $value->resolveIndirect();
            if (EnumCaseSupport::isEnumCaseVariable($key)) {
                throw new \TypeError('Illegal offset type');
            }
            if (Variable::TYPE_STRING !== $key->type && Variable::TYPE_INTEGER !== $key->type) {
                throw new \TypeError('Illegal offset type');
            }
            $pattern = Variable::TYPE_INTEGER === $key->type
                ? (string) $key->toInt()
                : $key->toString();
            $pairs[] = [$pattern, $value];
        }

        return $pairs;
    }

    private static function typeLabel(Variable $var): string
    {
        $var = $var->resolveIndirect();

        return match ($var->type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            Variable::TYPE_RESOURCE => 'resource',
            Variable::TYPE_ENUM_CASE => EnumCaseSupport::typeNameForVariable($var),
            default => 'mixed',
        };
    }
}
