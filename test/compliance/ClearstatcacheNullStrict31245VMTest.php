<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: clearstatcache(null) under strict_types → TypeError (#31245).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ClearstatcacheNullStrict31245VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'clearstatcache_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/clearstatcache_null_strict.phpt',
            'clearstatcache_null_strict.phpt'
        );
        yield 'clearstatcache_null_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/clearstatcache_null_soft_dep.phpt',
            'clearstatcache_null_soft_dep.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
