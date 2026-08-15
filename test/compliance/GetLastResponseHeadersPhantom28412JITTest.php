<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: get_last_response_headers() phantom — absent from php-src (#28412).
 *
 * @group llvm
 * @group jit
 */
final class GetLastResponseHeadersPhantom28412JITTest extends BaseTest
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
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(LlvmToolchain::readyFailureReason() ?? 'LLVM 9 not available');
        }
    }
}
