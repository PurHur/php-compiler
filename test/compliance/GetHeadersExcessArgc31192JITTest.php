<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: get_headers() ArgumentCountError wording (#31192). */
final class GetHeadersExcessArgc31192JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_get_headers_31192_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_get_headers_31192_jit.phpt',
            'excess_argc_get_headers_31192_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
