<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: fgets/fclose/fwrite/fputs/stream_get_contents excess argc → ArgumentCountError (#30721). */
final class StreamFileExcessArgc30721VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_stream_file_30721.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_stream_file_30721.phpt',
            'excess_argc_stream_file_30721.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
