<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for mb_stripos()/mb_strrpos()/mb_strrichr() (#7015) + mb_stristr family (#20006). */
final class MbStriposJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_stripos_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mb_stripos_jit.phpt',
            'mb_stripos_jit.phpt'
        );
        yield 'mb_stripos_enum_typeerror_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mb_stripos_enum_typeerror_jit.phpt',
            'mb_stripos_enum_typeerror_jit.phpt'
        );
        yield 'mb_stristr_family_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mb_stristr_family_jit.phpt',
            'mb_stristr_family_jit.phpt'
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
