<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for gethostbyname() (#7419).
 *
 * @group llvm
 * @group jit
 */
final class GethostbynameJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'gethostbyname_localhost_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/gethostbyname_localhost_jit.phpt',
            'gethostbyname_localhost_jit.phpt'
        );
        yield 'gethostbyname_null_forward84_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/gethostbyname_null_forward84_jit.phpt',
            'gethostbyname_null_forward84_jit.phpt'
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
