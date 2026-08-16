<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: mb_convert_encoding(null $from_encoding) uses internal encoding (#31488). */
final class MbConvertEncodingNullFromJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_convert_encoding_null_from_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_convert_encoding_null_from_jit.phpt',
            'mb_convert_encoding_null_from_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
