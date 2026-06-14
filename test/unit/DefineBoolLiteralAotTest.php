<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** @coversNothing */
final class DefineBoolLiteralAotTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    /**
     * @dataProvider defineBoolLiteralFixtures
     */
    public function testDefineBoolLiteralCompilesUnderSelfHostAot(string $fixture): void
    {
        $path = self::$root.'/'.$fixture;
        $out = self::$root.'/build/.define_bool_literal_aot_'.md5($fixture);
        @unlink($out);
        $cmd = implode(' ', array_map('escapeshellarg', [
            'env', 'PHP_COMPILER_SELFHOST_AOT=1',
            'php', self::$root.'/bin/compile.php', '-o', $out, $path,
        ])).' 2>&1';
        exec($cmd, $lines, $code);
        $log = implode("\n", $lines);
        self::assertSame(0, $code, "compile failed:\n".$log);
        self::assertFileExists($out);
        @unlink($out);
    }

    /** @return list<array{0: string}> */
    public static function defineBoolLiteralFixtures(): array
    {
        return [
            ['test/repro/define_bool_top.php'],
            ['test/repro/define_bool_literal.php'],
        ];
    }
}
