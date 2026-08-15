<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: tempnam(null $prefix) TypeError under strict_types (#31246). */
final class TempnamNullPrefixStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'tempnam_null_prefix_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/tempnam_null_prefix_strict.phpt',
            'tempnam_null_prefix_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
