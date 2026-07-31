<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: explode(null) DEP+ValueError on php-src-strict (#25942, re-#24695). */
final class ExplodeNullSeparatorVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'explode_null_separator_valueerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/explode_null_separator_valueerror.phpt',
            'explode_null_separator_valueerror.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
