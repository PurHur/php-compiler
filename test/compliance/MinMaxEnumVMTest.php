<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for min()/max() on enum cases (#5570). */
final class MinMaxEnumVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'max_min_enum_cases.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/max_min_enum_cases.phpt',
            'max_min_enum_cases.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
