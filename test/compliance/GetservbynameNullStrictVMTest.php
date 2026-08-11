<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: getservbyname(null) TypeError under strict_types (#30281). */
final class GetservbynameNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'getservbyname_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/getservbyname_null_strict.phpt',
            'getservbyname_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
