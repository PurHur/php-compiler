<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for gethostbyaddr() (#5854).
 *
 * @group llvm
 * @group jit
 */
final class GethostbyaddrJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'gethostbyaddr_loopback_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/gethostbyaddr_loopback_jit.phpt',
            'gethostbyaddr_loopback_jit.phpt'
        );
        yield 'gethostbyaddr_enum_typeerror_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/gethostbyaddr_enum_typeerror_jit.phpt',
            'gethostbyaddr_enum_typeerror_jit.phpt'
        );
        yield 'gethostbyaddr_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/gethostbyaddr_null_strict_jit.phpt',
            'gethostbyaddr_null_strict_jit.phpt'
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
