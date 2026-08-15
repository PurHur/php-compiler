<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: ob_start null $chunk_size under strict_types → TypeError (#31228).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ObStartNullChunkSize31228VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'ob_start_null_chunk_size_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/ob_start_null_chunk_size_strict.phpt',
            'ob_start_null_chunk_size_strict.phpt'
        );
        yield 'ob_start_null_chunk_size_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/ob_start_null_chunk_size_soft_dep.phpt',
            'ob_start_null_chunk_size_soft_dep.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
