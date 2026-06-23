<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for microtime() (#2186). */
final class MicrotimeVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'microtime.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/microtime.phpt',
            'microtime.phpt'
        );
        yield 'microtime_named.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/microtime_named.phpt',
            'microtime_named.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
