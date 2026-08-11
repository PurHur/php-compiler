<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: stream_get_contents(..., null) $offset TypeError under strict_types (#30249). */
final class StreamGetContentsOffsetNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'stream_get_contents_offset_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/stream_get_contents_offset_null_strict.phpt',
            'stream_get_contents_offset_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
