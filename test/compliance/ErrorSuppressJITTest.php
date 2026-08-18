<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for `@` error-control (issues #3546, #4070).
 *
 * @group llvm
 * @group jit
 */
final class ErrorSuppressJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'error_control_operator.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/error_control_operator.phpt',
            'error_control_operator.phpt'
        );
        yield 'error_suppress_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/error_suppress_jit.phpt',
            'error_suppress_jit.phpt'
        );
        yield 'at_silence_assign_undef_rhs.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/at_silence_assign_undef_rhs.phpt',
            'at_silence_assign_undef_rhs.phpt'
        );
        yield 'at_silence_undef_closure_error_get_last.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/at_silence_undef_closure_error_get_last.phpt',
            'at_silence_undef_closure_error_get_last.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh or use the 22.04-dev Docker image.'
            );
        }
    }
}
