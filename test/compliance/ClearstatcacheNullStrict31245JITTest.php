<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: clearstatcache(null) TypeError under strict_types (#31245). */
final class ClearstatcacheNullStrict31245JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'clearstatcache_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/clearstatcache_null_strict_jit.phpt',
            'clearstatcache_null_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
