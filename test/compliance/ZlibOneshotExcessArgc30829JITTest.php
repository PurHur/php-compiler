<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: zlib one-shot/file helpers ArgumentCountError wording (#30829). */
final class ZlibOneshotExcessArgc30829JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_zlib_oneshot_30829_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_zlib_oneshot_30829_jit.phpt',
            'excess_argc_zlib_oneshot_30829_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
