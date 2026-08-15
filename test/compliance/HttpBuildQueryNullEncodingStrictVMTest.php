<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: http_build_query(null $encoding_type) TypeError under strict_types (#31247). */
final class HttpBuildQueryNullEncodingStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'http_build_query_null_encoding_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/http_build_query_null_encoding_strict.phpt',
            'http_build_query_null_encoding_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
