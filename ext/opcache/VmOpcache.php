<?php

declare(strict_types=1);

namespace PHPCompiler\ext\opcache;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Zend-shaped opcache probe payloads for VM-only stubs (php-src ext/opcache; issue #4421).
 *
 * This compiler does not embed Zend OPcache. {@see opcache_get_status} returns `false`
 * when inactive (#21755); configuration/reset/compile/invalidate/is_script_cached stubs
 * remain for deploy probes (#23834).
 */
final class VmOpcache
{
    public static function disabledStatus(bool $includeScripts): HashTable
    {
        $status = new HashTable();
        self::hashSetBool($status, 'opcache_enabled', false);
        self::hashSetBool($status, 'cache_full', false);
        self::hashSetBool($status, 'restart_pending', false);
        self::hashSetBool($status, 'restart_in_progress', false);
        self::hashSetArray($status, 'memory_usage', self::memoryUsageTable());
        self::hashSetArray($status, 'interned_strings_usage', self::internedStringsUsageTable());
        self::hashSetArray($status, 'opcache_statistics', self::statisticsTable());
        self::hashSetArray($status, 'jit', self::jitTable());
        if ($includeScripts) {
            self::hashSetArray($status, 'scripts', new HashTable());
        }

        return $status;
    }

    public static function disabledConfiguration(): HashTable
    {
        $configuration = new HashTable();
        $directives = new HashTable();
        self::hashSetBool($directives, 'opcache.enable', false);
        self::hashSetBool($directives, 'opcache.enable_cli', false);
        self::hashSetArray($configuration, 'directives', $directives);

        $version = new HashTable();
        self::hashSetString($version, 'version', CompilerVersion::VERSION);
        self::hashSetString($version, 'opcache_product_name', 'PHP-Compiler OPcache stub');
        self::hashSetArray($configuration, 'version', $version);
        self::hashSetArray($configuration, 'blacklist', new HashTable());

        return $configuration;
    }

    private static function memoryUsageTable(): HashTable
    {
        $ht = new HashTable();
        self::hashSetLong($ht, 'used_memory', 0);
        self::hashSetLong($ht, 'free_memory', 0);
        self::hashSetLong($ht, 'wasted_memory', 0);
        self::hashSetFloat($ht, 'current_wasted_percentage', 0.0);

        return $ht;
    }

    private static function internedStringsUsageTable(): HashTable
    {
        $ht = new HashTable();
        self::hashSetLong($ht, 'buffer_size', 0);
        self::hashSetLong($ht, 'used_memory', 0);
        self::hashSetLong($ht, 'free_memory', 0);
        self::hashSetLong($ht, 'number_of_strings', 0);

        return $ht;
    }

    private static function statisticsTable(): HashTable
    {
        $ht = new HashTable();
        self::hashSetLong($ht, 'num_cached_scripts', 0);
        self::hashSetLong($ht, 'num_cached_keys', 0);
        self::hashSetLong($ht, 'max_cached_keys', 0);
        self::hashSetLong($ht, 'hits', 0);
        self::hashSetLong($ht, 'start_time', 0);
        self::hashSetLong($ht, 'last_restart_time', 0);
        self::hashSetLong($ht, 'oom_restarts', 0);
        self::hashSetLong($ht, 'hash_restarts', 0);
        self::hashSetLong($ht, 'manual_restarts', 0);
        self::hashSetLong($ht, 'misses', 0);
        self::hashSetLong($ht, 'blacklist_misses', 0);
        self::hashSetFloat($ht, 'blacklist_miss_ratio', 0.0);
        self::hashSetFloat($ht, 'opcache_hit_rate', 0.0);

        return $ht;
    }

    private static function jitTable(): HashTable
    {
        $ht = new HashTable();
        self::hashSetBool($ht, 'enabled', false);
        self::hashSetBool($ht, 'on', false);
        self::hashSetLong($ht, 'kind', 0);
        self::hashSetLong($ht, 'opt_level', 0);
        self::hashSetLong($ht, 'opt_flags', 0);
        self::hashSetLong($ht, 'buffer_size', 0);
        self::hashSetLong($ht, 'buffer_free', 0);

        return $ht;
    }

    private static function hashSetArray(HashTable $ht, string $key, HashTable $value): void
    {
        $var = new Variable();
        $var->array($value);
        $ht->add($key, $var);
    }

    private static function hashSetLong(HashTable $ht, string $key, int $value): void
    {
        $ht->add($key, self::intVariable($value));
    }

    private static function hashSetBool(HashTable $ht, string $key, bool $value): void
    {
        $var = new Variable(Variable::TYPE_BOOLEAN);
        $var->bool($value);
        $ht->add($key, $var);
    }

    private static function hashSetFloat(HashTable $ht, string $key, float $value): void
    {
        $var = new Variable(Variable::TYPE_FLOAT);
        $var->float($value);
        $ht->add($key, $var);
    }

    private static function hashSetString(HashTable $ht, string $key, string $value): void
    {
        $var = new Variable();
        $var->string($value);
        $ht->add($key, $var);
    }

    private static function intVariable(int $value): Variable
    {
        $var = new Variable(Variable::TYPE_INTEGER);
        $var->int($value);

        return $var;
    }
}
