<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: stream_context_get_options() ArgumentCountError wording (#30785). */
final class StreamContextGetOptionsExcessArgc30785VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_stream_context_get_options_30785.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_stream_context_get_options_30785.phpt',
            'excess_argc_stream_context_get_options_30785.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
