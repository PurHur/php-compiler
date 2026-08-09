<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: mb_str_pad(..., '') ValueError "must not be empty" (#29422, php-src mbstring.c). */
final class MbStrPadEmptyPadMessageVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_str_pad_empty_pad_must_not_be_empty.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_str_pad_empty_pad_must_not_be_empty.phpt',
            'mb_str_pad_empty_pad_must_not_be_empty.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
