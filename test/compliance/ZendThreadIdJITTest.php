<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for zend_thread_id() (#6870).
 *
 * @group llvm
 * @group jit
 */
final class ZendThreadIdJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        if (!CompilerVersion::supportsZendThreadId()) {
            return;
        }
        yield 'zend_thread_id_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/zend_thread_id_jit.phpt',
            'zend_thread_id_jit.phpt'
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
