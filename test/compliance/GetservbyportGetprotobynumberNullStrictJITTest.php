<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: getservbyport(null)/getprotobynumber(null) TypeError under strict_types (#30283). */
final class GetservbyportGetprotobynumberNullStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'getservbyport_getprotobynumber_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/getservbyport_getprotobynumber_null_strict_jit.phpt',
            'getservbyport_getprotobynumber_null_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
