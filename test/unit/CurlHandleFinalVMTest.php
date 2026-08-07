<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for CurlHandle/Multi/Share final (ext/curl/curl.stub.php; #28371). */
final class CurlHandleFinalVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        yield 'curl_handle_classes_final.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/stdlib/curl_handle_classes_final.phpt',
            'curl_handle_classes_final.phpt'
        );
        yield 'curl_handle_classes_extend_final.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/stdlib/curl_handle_classes_extend_final.phpt',
            'curl_handle_classes_extend_final.phpt'
        );
        yield 'curl_multi_handle_extend_final.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/stdlib/curl_multi_handle_extend_final.phpt',
            'curl_multi_handle_extend_final.phpt'
        );
        yield 'curl_share_handle_extend_final.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/stdlib/curl_share_handle_extend_final.phpt',
            'curl_share_handle_extend_final.phpt'
        );
    }
}
