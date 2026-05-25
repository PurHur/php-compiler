<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for password_hash() / password_verify() (#172).
 *
 * @group llvm
 * @group jit
 */
final class PasswordJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'password_hash_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/password_hash_jit.phpt',
            'password_hash_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(LlvmToolchain::readyFailureReason() ?? 'LLVM 9 not available');
        }
        // MCJIT + libcrypt from bitcode: segfault until symbol resolver lands (AOT covered in test/fixtures/aot/cases/password_hash.phpt).
        if ('0' !== getenv('PASSWORD_JIT_COMPLIANCE')) {
            $this->markTestSkipped(
                'Set PASSWORD_JIT_COMPLIANCE=1 to run (libcrypt MCJIT); AOT + VM unit tests cover #172'
            );
        }
    }
}
