<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for fsockopen() procedural TCP wrapper (issue #8954). */
final class FsockopenBuiltinTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/../compliance/cases/stdlib/fsockopen.phpt';
        yield 'fsockopen.phpt' => self::parsePHPT($path, 'fsockopen.phpt');

        $udp = __DIR__.'/../compliance/cases/stdlib/fsockopen_udp_scheme.phpt';
        yield 'fsockopen_udp_scheme.phpt' => self::parsePHPT($udp, 'fsockopen_udp_scheme.phpt');

        $soft = __DIR__.'/../compliance/cases/stdlib/fsockopen_null_soft.phpt';
        yield 'fsockopen_null_soft.phpt' => self::parsePHPT($soft, 'fsockopen_null_soft.phpt');

        $strict = __DIR__.'/../compliance/cases/stdlib/fsockopen_null_strict.phpt';
        yield 'fsockopen_null_strict.phpt' => self::parsePHPT($strict, 'fsockopen_null_strict.phpt');
    }
}
