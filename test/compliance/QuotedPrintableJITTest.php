<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for quoted_printable_encode/decode (#5376).
 *
 * @group llvm
 * @group jit
 */
final class QuotedPrintableJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'quoted_printable_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/quoted_printable_jit.phpt',
            'quoted_printable_jit.phpt'
        );
        yield 'quoted_printable_type_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/quoted_printable_type_jit.phpt',
            'quoted_printable_type_jit.phpt'
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
