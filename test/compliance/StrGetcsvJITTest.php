<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for str_getcsv(). */
final class StrGetcsvJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'str_getcsv_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_getcsv_jit.phpt',
            'str_getcsv_jit.phpt'
        );
        yield 'str_getcsv_enum_type_error_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_getcsv_enum_type_error_jit.phpt',
            'str_getcsv_enum_type_error_jit.phpt'
        );
        yield 'str_getcsv_newline_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_getcsv_newline_jit.phpt',
            'str_getcsv_newline_jit.phpt'
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
