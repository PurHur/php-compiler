<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: opcache_is_script_cached_in_file_cache PROFILE gates (#27675). */
final class OpcacheIsScriptCachedInFileCacheVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'opcache_is_script_cached_in_file_cache_85.phpt' => self::parsePHPT(
            __DIR__.'/cases/ext/opcache_is_script_cached_in_file_cache_85.phpt',
            'opcache_is_script_cached_in_file_cache_85.phpt'
        );
        yield 'opcache_is_script_cached_in_file_cache_84.phpt' => self::parsePHPT(
            __DIR__.'/cases/ext/opcache_is_script_cached_in_file_cache_84.phpt',
            'opcache_is_script_cached_in_file_cache_84.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
