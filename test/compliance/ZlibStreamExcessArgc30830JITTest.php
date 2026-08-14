<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: zlib stream helpers ArgumentCountError wording (#30830). */
final class ZlibStreamExcessArgc30830JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_zlib_stream_30830_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_zlib_stream_30830_jit.phpt',
            'excess_argc_zlib_stream_30830_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
