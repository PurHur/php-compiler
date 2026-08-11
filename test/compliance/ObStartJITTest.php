<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for ob_start / ob_get_clean / ob_end_flush (#118, #1056).
 *
 * @group llvm
 * @group jit
 */
final class ObStartJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'ob_start_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/ob_start_jit.phpt',
            'ob_start_jit.phpt'
        );
        yield 'ob_start_null_callback.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/ob_start_null_callback.phpt',
            'ob_start_null_callback.phpt'
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
