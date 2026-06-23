<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for gethostbynamel() (#3707). */
final class GethostbynamelVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'gethostbynamel_localhost.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/gethostbynamel_localhost.phpt',
            'gethostbynamel_localhost.phpt'
        );
        yield 'gethostbynamel_localhost_duplicates.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/gethostbynamel_localhost_duplicates.phpt',
            'gethostbynamel_localhost_duplicates.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
