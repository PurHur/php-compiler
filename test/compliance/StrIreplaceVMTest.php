<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for str_ireplace(). */
final class StrIreplaceVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'str_ireplace.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/str_ireplace.phpt',
            'str_ireplace.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
