<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for http_get_last_response_headers() / get_last_response_headers() (#7236, #8769). */
final class HttpLastResponseHeadersVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'http_get_last_response_headers_empty.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/http_get_last_response_headers_empty.phpt',
            'http_get_last_response_headers_empty.phpt'
        );
        yield 'get_last_response_headers.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/get_last_response_headers.phpt',
            'get_last_response_headers.phpt'
        );
        yield 'http_clear_last_response_headers.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/http_clear_last_response_headers.phpt',
            'http_clear_last_response_headers.phpt'
        );
        yield 'http_get_last_response_headers_https.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/http_get_last_response_headers_https.phpt',
            'http_get_last_response_headers_https.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
