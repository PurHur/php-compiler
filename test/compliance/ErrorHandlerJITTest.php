<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for set_error_handler() / restore_error_handler() (#1379, #1492).
 *
 * @group llvm
 * @group jit
 */
final class ErrorHandlerJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'set_error_handler_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/set_error_handler_jit.phpt',
            'set_error_handler_jit.phpt'
        );
        yield 'restore_error_handler_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/restore_error_handler_jit.phpt',
            'restore_error_handler_jit.phpt'
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
