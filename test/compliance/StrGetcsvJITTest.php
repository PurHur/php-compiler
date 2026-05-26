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
    }
}
