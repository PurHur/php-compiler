<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for intval()/floatval() on backed enum cases (#5623). */
final class IntvalBackedEnumVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'intval_backed_enum.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/intval_backed_enum.phpt',
            'intval_backed_enum.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
