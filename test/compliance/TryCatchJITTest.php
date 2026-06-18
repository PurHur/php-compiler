<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__ . '/../BaseTest.php';

/**
 * JIT compliance for try/catch/throw (#2084, #1056, #1492).
 *
 * @group llvm
 * @group jit
 */
final class TryCatchJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'try_catch_echo_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/try_catch_echo_jit.phpt',
            'try_catch_echo_jit.phpt'
        );
        yield 'throw_in_function_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/throw_in_function_jit.phpt',
            'throw_in_function_jit.phpt'
        );
        yield 'throw_expression_assign_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/throw_expression_assign_jit.phpt',
            'throw_expression_assign_jit.phpt'
        );
        yield 'throw_expression.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/throw_expression.phpt',
            'throw_expression.phpt'
        );
        yield 'throw_expression_return_elvis.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/throw_expression_return_elvis.phpt',
            'throw_expression_return_elvis.phpt'
        );
        yield 'throws_user_empty_class_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/throws_user_empty_class_jit.phpt',
            'throws_user_empty_class_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__ . '/../../bin/jit.php');
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh or use the 22.04-dev Docker image.'
            );
        }
    }
}
