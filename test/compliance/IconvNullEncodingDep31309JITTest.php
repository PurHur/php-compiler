<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: iconv() null encoding soft-null DEP (#31309). */
final class IconvNullEncodingDep31309JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'iconv_null_encoding_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/iconv/iconv_null_encoding_soft_dep.phpt',
            'iconv_null_encoding_soft_dep.phpt'
        );
        yield 'iconv_null_encoding_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/iconv/iconv_null_encoding_strict.phpt',
            'iconv_null_encoding_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
