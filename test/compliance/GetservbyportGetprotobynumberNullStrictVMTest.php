<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: getservbyport(null)/getprotobynumber(null) TypeError under strict_types (#30283). */
final class GetservbyportGetprotobynumberNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'getservbyport_getprotobynumber_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/getservbyport_getprotobynumber_null_strict.phpt',
            'getservbyport_getprotobynumber_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
