<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for iconv_strpos()/iconv_strrpos() Reflection stub types (#28586). */
final class IconvStrposStrrposReflectionVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'iconv_strpos_strrpos_reflection.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/iconv/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
