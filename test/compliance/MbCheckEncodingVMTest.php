<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for mb_check_encoding() (#4571). */
final class MbCheckEncodingVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_check_encoding.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mb_check_encoding.phpt',
            'mb_check_encoding.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
