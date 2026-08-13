<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: stream/file builtins excess argc → ArgumentCountError (#30584). */
final class StreamFileExcessArgc30584VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_stream_file_30584.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_stream_file_30584.phpt',
            'excess_argc_stream_file_30584.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
