<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for mb_convert_encoding() Reflection stub types (#26466). */
final class MbConvertEncodingReflectionVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'mb_convert_encoding_reflection_types.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/mbstring/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
