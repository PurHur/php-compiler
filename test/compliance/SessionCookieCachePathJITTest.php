<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: session_get/set_cookie_params + cache_limiter + save_path (#30758).
 *
 * @group llvm
 * @group jit
 */
final class SessionCookieCachePathJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'session_cookie_cache_path_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/session/session_cookie_cache_path_jit.phpt',
            'session_cookie_cache_path_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
