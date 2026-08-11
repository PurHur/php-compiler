<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for token_get_all() (#3171, #4561).
 *
 * @group llvm
 * @group jit
 */
final class TokenGetAllJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'token_get_all_native_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/token_get_all_native_jit.phpt',
            'token_get_all_native_jit.phpt'
        );
        yield 'token_get_all_runtime_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/token_get_all_runtime_jit.phpt',
            'token_get_all_runtime_jit.phpt'
        );
        yield 'token_get_all_concat_runtime_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/token_get_all_concat_runtime_jit.phpt',
            'token_get_all_concat_runtime_jit.phpt'
        );
        yield 'token_get_all_null_forward84_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/token_get_all_null_forward84_jit.phpt',
            'token_get_all_null_forward84_jit.phpt'
        );
        yield 'token_get_all_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/token_get_all_null_strict_jit.phpt',
            'token_get_all_null_strict_jit.phpt'
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
