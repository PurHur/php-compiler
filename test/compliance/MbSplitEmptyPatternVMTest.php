<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: mb_split("") returns subject array (#29496, php-src php_mbregex.c). */
final class MbSplitEmptyPatternVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_split_empty_pattern.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mb_split_empty_pattern.phpt',
            'mb_split_empty_pattern.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
