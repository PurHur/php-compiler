<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for set_exception_handler() / restore_exception_handler() (#4311, #3146).
 *
 * @group llvm
 * @group jit
 */
final class ExceptionHandlerJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'exception_handler_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/exception_handler_jit.phpt',
            'exception_handler_jit.phpt'
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
