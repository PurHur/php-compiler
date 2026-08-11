<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: mb_ereg_replace/mb_decode_mimeheader(null) TypeError under strict_types (#30311). */
final class MbEregReplaceDecodeMimeheaderNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_ereg_replace_decode_mimeheader_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_ereg_replace_decode_mimeheader_null_strict.phpt',
            'mb_ereg_replace_decode_mimeheader_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
