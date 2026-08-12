<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: explode('') ValueError "cannot be empty" (#29275, php-src string.c). */
final class ExplodeEmptySeparatorMessageVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'explode_empty_separator_must_not_be_empty.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/explode_empty_separator_must_not_be_empty.phpt',
            'explode_empty_separator_must_not_be_empty.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
