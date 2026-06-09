<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for mb_convert_case(). */
final class MbConvertCaseJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_convert_case_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mb_convert_case_jit.phpt',
            'mb_convert_case_jit.phpt'
        );
        yield 'mb_convert_case_enum_typeerror_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mb_convert_case_enum_typeerror_jit.phpt',
            'mb_convert_case_enum_typeerror_jit.phpt'
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
