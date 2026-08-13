<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPLLVM\Value;

/** Materialize date_parse()/date_parse_from_format() output at JIT compile time (#6172). */
final class JitDateParseMaterializer
{
    /**
     * @param array{
     *   year: int|false,
     *   month: int|false,
     *   day: int|false,
     *   hour: int|false,
     *   minute: int|false,
     *   second: int|false,
     *   fraction: float|false,
     *   warning_count: int,
     *   warnings: array<int, string>,
     *   error_count: int,
     *   errors: array<int, string>,
     *   is_localtime: bool
     * } $result
     */
    public static function materialize(Context $context, array $result): Value
    {
        $ht = HashTableHelper::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $dbl = $context->getTypeFromString('double');

        foreach (['year', 'month', 'day', 'hour', 'minute', 'second'] as $key) {
            self::setIntOrFalse($context, $ht, $key, $result[$key], $i64, $i1);
        }
        self::setFloatOrFalse($context, $ht, 'fraction', $result['fraction'], $dbl, $i1);

        // php-src zval_from_error_container(): warning_count → warnings → error_count → errors (#25485)
        $keyStr = $context->builder->load($context->constantStringFromString('warning_count'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $keyStr,
            $i64->constInt($result['warning_count'], false)
        );
        self::setNestedMessages($context, $ht, 'warnings', $result['warnings']);
        $keyStr = $context->builder->load($context->constantStringFromString('error_count'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $keyStr,
            $i64->constInt($result['error_count'], false)
        );
        self::setNestedMessages($context, $ht, 'errors', $result['errors']);

        // php-src date_parse: is_localtime, then zone metadata, then relative (#25485)
        $keyStr = $context->builder->load($context->constantStringFromString('is_localtime'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyBool'),
            $ht,
            $keyStr,
            $i1->constInt($result['is_localtime'] ? 1 : 0, false)
        );

        if (isset($result['zone_type'])) {
            $keyStr = $context->builder->load($context->constantStringFromString('zone_type'));
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyLong'),
                $ht,
                $keyStr,
                $i64->constInt($result['zone_type'], false)
            );
        }
        if (isset($result['zone'])) {
            $keyStr = $context->builder->load($context->constantStringFromString('zone'));
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyLong'),
                $ht,
                $keyStr,
                $i64->constInt($result['zone'], false)
            );
        }
        if (isset($result['is_dst'])) {
            $keyStr = $context->builder->load($context->constantStringFromString('is_dst'));
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyBool'),
                $ht,
                $keyStr,
                $i1->constInt($result['is_dst'] ? 1 : 0, false)
            );
        }
        if (isset($result['tz_abbr'])) {
            $keyStr = $context->builder->load($context->constantStringFromString('tz_abbr'));
            $abbrStr = $context->builder->load($context->constantStringFromString($result['tz_abbr']));
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyString'),
                $ht,
                $keyStr,
                $abbrStr
            );
        }
        if (isset($result['tz_id'])) {
            $keyStr = $context->builder->load($context->constantStringFromString('tz_id'));
            $tzStr = $context->builder->load($context->constantStringFromString($result['tz_id']));
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyString'),
                $ht,
                $keyStr,
                $tzStr
            );
        }

        if (isset($result['relative']) && \is_array($result['relative'])) {
            $relative = HashTableHelper::alloc($context);
            foreach (['year', 'month', 'day', 'hour', 'minute', 'second', 'weekday'] as $relKey) {
                $keyStr = $context->builder->load($context->constantStringFromString($relKey));
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyLong'),
                    $relative,
                    $keyStr,
                    $i64->constInt((int) $result['relative'][$relKey], false)
                );
            }
            $keyStr = $context->builder->load($context->constantStringFromString('relative'));
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyHashtable'),
                $ht,
                $keyStr,
                $relative
            );
        }

        return $ht;
    }

    /**
     * DateTime::getLastErrors() / date_get_last_errors() bag only (#30749).
     *
     * php-src zval_from_error_container(): warning_count → warnings → error_count → errors (#25485)
     *
     * @param array{
     *   warning_count: int,
     *   warnings: array<int, string>,
     *   error_count: int,
     *   errors: array<int, string>
     * } $result
     */
    public static function materializeLastErrors(Context $context, array $result): Value
    {
        $ht = HashTableHelper::alloc($context);
        $i64 = $context->getTypeFromString('int64');

        $keyStr = $context->builder->load($context->constantStringFromString('warning_count'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $keyStr,
            $i64->constInt($result['warning_count'], false)
        );
        self::setNestedMessages($context, $ht, 'warnings', $result['warnings']);
        $keyStr = $context->builder->load($context->constantStringFromString('error_count'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $keyStr,
            $i64->constInt($result['error_count'], false)
        );
        self::setNestedMessages($context, $ht, 'errors', $result['errors']);

        return $ht;
    }

    private static function setIntOrFalse(
        Context $context,
        Value $ht,
        string $key,
        int|false $value,
        $i64,
        $i1
    ): void {
        $keyStr = $context->builder->load($context->constantStringFromString($key));
        if (false === $value) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyBool'),
                $ht,
                $keyStr,
                $i1->constInt(0, false)
            );

            return;
        }
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $keyStr,
            $i64->constInt($value, false)
        );
    }

    private static function setFloatOrFalse(
        Context $context,
        Value $ht,
        string $key,
        float|false $value,
        $dbl,
        $i1
    ): void {
        $keyStr = $context->builder->load($context->constantStringFromString($key));
        if (false === $value) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyBool'),
                $ht,
                $keyStr,
                $i1->constInt(0, false)
            );

            return;
        }
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyDouble'),
            $ht,
            $keyStr,
            $dbl->constReal((float) $value, false)
        );
    }

    /**
     * @param array<int, string> $messages
     */
    private static function setNestedMessages(
        Context $context,
        Value $ht,
        string $key,
        array $messages
    ): void {
        $nested = HashTableHelper::alloc($context);
        foreach ($messages as $pos => $message) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringAt'),
                $nested,
                $context->getTypeFromString('size_t')->constInt((int) $pos, false),
                $context->builder->load($context->constantStringFromString($message))
            );
        }
        $keyStr = $context->builder->load($context->constantStringFromString($key));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $ht,
            $keyStr,
            $nested
        );
    }
}
