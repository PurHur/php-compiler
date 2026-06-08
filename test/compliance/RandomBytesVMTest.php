<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for random_bytes() enum strict guards (#6160). */
final class RandomBytesVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'random_bytes_enum_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/random_bytes_enum_typeerror.phpt',
            'random_bytes_enum_typeerror.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
