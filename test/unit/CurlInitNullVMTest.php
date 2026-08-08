<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance for curl_init(?string $url = null) — no null-to-string Deprecated (#28563).
 */
final class CurlInitNullVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        yield 'curl_init_null_url_no_deprecation.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/stdlib/curl_init_null_url_no_deprecation.phpt',
            'curl_init_null_url_no_deprecation.phpt'
        );
    }
}
