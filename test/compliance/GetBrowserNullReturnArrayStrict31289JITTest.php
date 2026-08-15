<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: get_browser(..., null) $return_array under strict_types → TypeError (#31289). */
final class GetBrowserNullReturnArrayStrict31289JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'get_browser_null_return_array_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/get_browser_null_return_array_strict_jit.phpt',
            'get_browser_null_return_array_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
