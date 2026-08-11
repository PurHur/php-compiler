<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for gethostbyaddr() (#5854). */
final class GethostbyaddrVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'gethostbyaddr_loopback.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/gethostbyaddr_loopback.phpt',
            'gethostbyaddr_loopback.phpt'
        );
        yield 'gethostbyaddr_enum_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/gethostbyaddr_enum_typeerror.phpt',
            'gethostbyaddr_enum_typeerror.phpt'
        );
        yield 'gethostbyaddr_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/gethostbyaddr_null_strict.phpt',
            'gethostbyaddr_null_strict.phpt'
        );
        yield 'gethostbyaddr_not_found_ip.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/gethostbyaddr_not_found_ip.phpt',
            'gethostbyaddr_not_found_ip.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
