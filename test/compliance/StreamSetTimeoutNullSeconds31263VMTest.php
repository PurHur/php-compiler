<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: stream_set_timeout null $seconds under strict_types → TypeError (#31263).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class StreamSetTimeoutNullSeconds31263VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'stream_set_timeout_null_seconds_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/stream_set_timeout_null_seconds_strict.phpt',
            'stream_set_timeout_null_seconds_strict.phpt'
        );
        yield 'stream_set_timeout_null_seconds_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/stream_set_timeout_null_seconds_soft_dep.phpt',
            'stream_set_timeout_null_seconds_soft_dep.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
