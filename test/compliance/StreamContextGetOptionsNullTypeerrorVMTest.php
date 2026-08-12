<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: stream_context_get_options(null) TypeError names $stream_or_context (#30418). */
final class StreamContextGetOptionsNullTypeerrorVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'stream_context_get_options_null_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/streams/stream_context_get_options_null_typeerror.phpt',
            'stream_context_get_options_null_typeerror.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
