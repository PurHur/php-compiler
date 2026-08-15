<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for iconv_mime_decode() Reflection stub names (#24378). */
final class IconvMimeDecodeReflectionVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'iconv_mime_decode_reflection.phpt';
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
