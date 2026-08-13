<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: session_get/set_cookie_params + cache_limiter + save_path (#30758).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class SessionCookieCachePathAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'session_cookie_cache_path.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('session cookie/cache/path AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
