<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT compliance for sys_getloadavg() (#3464). */
final class SysGetloadavgJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'sys_getloadavg_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/sys_getloadavg_jit.phpt',
            'sys_getloadavg_jit.phpt'
        );
        yield 'sys_getloadavg_argc_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/sys_getloadavg_argc_jit.phpt',
            'sys_getloadavg_argc_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
