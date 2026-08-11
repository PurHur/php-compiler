<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for iconv() Reflection return string|false (#28424). */
final class IconvReflectionReturnVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'iconv_reflection_return.phpt';
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
