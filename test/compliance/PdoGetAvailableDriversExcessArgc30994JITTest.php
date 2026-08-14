<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: PDO::getAvailableDrivers / pdo_drivers excess argc ArgumentCountError (#30994). */
final class PdoGetAvailableDriversExcessArgc30994JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'pdo_getavailabledrivers_excess_argc_30994_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/pdo_getavailabledrivers_excess_argc_30994_jit.phpt',
            'pdo_getavailabledrivers_excess_argc_30994_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
