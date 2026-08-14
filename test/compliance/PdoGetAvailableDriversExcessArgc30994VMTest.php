<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: PDO::getAvailableDrivers / pdo_drivers excess argc ArgumentCountError (#30994). */
final class PdoGetAvailableDriversExcessArgc30994VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'pdo_getavailabledrivers_excess_argc_30994.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/pdo_getavailabledrivers_excess_argc_30994.phpt',
            'pdo_getavailabledrivers_excess_argc_30994.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
