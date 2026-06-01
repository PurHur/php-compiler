<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for sys_getloadavg() (#3464). */
final class SysGetloadavgVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'sys_getloadavg.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/sys_getloadavg.phpt',
            'sys_getloadavg.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
