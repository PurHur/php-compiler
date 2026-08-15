<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: get_last_response_headers() phantom — absent from php-src (#28412).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class GetLastResponseHeadersPhantom28412VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'get_last_response_headers_phantom.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/get_last_response_headers_phantom.phpt',
            'get_last_response_headers_phantom.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
