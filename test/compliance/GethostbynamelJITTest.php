<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for gethostbynamel() (#3707).
 *
 * @group llvm
 * @group jit
 */
final class GethostbynamelJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'gethostbynamel_localhost_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/gethostbynamel_localhost_jit.phpt',
            'gethostbynamel_localhost_jit.phpt'
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
