<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for mb_scrub() (#6050). */
final class MbScrubVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_scrub.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mb_scrub.phpt',
            'mb_scrub.phpt'
        );
        yield 'mb_scrub_enum_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mb_scrub_enum_typeerror.phpt',
            'mb_scrub_enum_typeerror.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
