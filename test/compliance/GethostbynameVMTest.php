<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for gethostbyname() (#7419). */
final class GethostbynameVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'gethostbyname_localhost.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/gethostbyname_localhost.phpt',
            'gethostbyname_localhost.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
