<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: ob_start null $chunk_size under strict_types → TypeError (#31228).
 */
final class ObStartNullChunkSize31228JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'ob_start_null_chunk_size_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/ob_start_null_chunk_size_strict_jit.phpt',
            'ob_start_null_chunk_size_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
