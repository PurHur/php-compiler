<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for spl_autoload_register() (#1776, #2441).
 *
 * @group llvm
 * @group jit
 */
final class SplAutoloadRegisterJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'spl_autoload_register_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/spl_autoload_register_jit.phpt',
            'spl_autoload_register_jit.phpt'
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
