<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for http_get_last_response_headers() / get_last_response_headers() (#7236, #8769).
 *
 * @group llvm
 * @group jit
 */
final class HttpLastResponseHeadersJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        if (!CompilerVersion::supportsHttpLastResponseHeaders()) {
            return;
        }

        yield 'get_last_response_headers_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/get_last_response_headers_jit.phpt',
            'get_last_response_headers_jit.phpt'
        );
        yield 'http_clear_last_response_headers_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/http_clear_last_response_headers_jit.phpt',
            'http_clear_last_response_headers_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(LlvmToolchain::readyFailureReason() ?? 'LLVM 9 not available');
        }
    }
}
