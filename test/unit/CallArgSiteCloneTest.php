<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** @coversNothing */
final class CallArgSiteCloneTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testArrayPushSpreadPatternCompilesUnderSelfHostAot(): void
    {
        $fixture = self::$root.'/test/repro/array_push_spread_repro.php';
        $out = self::$root.'/build/.call_arg_site_clone_test_aot';
        @unlink($out);
        $cmd = implode(' ', array_map('escapeshellarg', [
            'env', 'PHP_COMPILER_SELFHOST_AOT=1',
            'php', self::$root.'/bin/compile.php', '-o', $out, $fixture,
        ])).' 2>&1';
        exec($cmd, $lines, $code);
        $log = implode("\n", $lines);
        self::assertSame(0, $code, "compile failed:\n".$log);
        self::assertFileExists($out);
        @unlink($out);
    }
}
