<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** Focused JIT PHPT for get_browser Reflection/named args (#23382). */
final class GetBrowserReflectionNamed23382JITTest extends BaseTest
{
    protected string $BIN = __DIR__.'/../../bin/jit.php';

    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/stdlib/get_browser_reflection_named.phpt';
        [$name, $code, $sections] = self::parsePHPT($path, 'get_browser_reflection_named');
        yield $name => [$name, $code, $sections];
    }
}
