<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: session_cache_limiter(null) getter, no Deprecated (#30396). */
final class SessionCacheLimiterNullGetterVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'session_cache_limiter_null_getter.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/session_cache_limiter_null_getter.phpt',
            'session_cache_limiter_null_getter.phpt'
        );
        yield 'session_cache_limiter_null_getter_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/session_cache_limiter_null_getter_strict.phpt',
            'session_cache_limiter_null_getter_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
