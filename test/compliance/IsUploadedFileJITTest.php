<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for is_uploaded_file() (#2204).
 *
 * @group llvm
 * @group jit
 */
final class IsUploadedFileJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'is_uploaded_file_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/is_uploaded_file_jit.phpt',
            'is_uploaded_file_jit.phpt'
        );
        yield 'is_uploaded_file_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/is_uploaded_file_null_strict_jit.phpt',
            'is_uploaded_file_null_strict_jit.phpt'
        );
        yield 'named_args_is_uploaded_file_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/named_args_is_uploaded_file_jit.phpt',
            'named_args_is_uploaded_file_jit.phpt'
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
