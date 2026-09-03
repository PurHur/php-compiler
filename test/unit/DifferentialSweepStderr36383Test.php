<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** `--stderr` stream+exit differential (#36383); empty corpus must not look green (#36248). */
final class DifferentialSweepStderr36383Test extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testErrorsCountFileMatchesCorpus(): void
    {
        $dir = self::$root.'/test/differential/cases/errors';
        $countFile = $dir.'/COUNT';
        $this->assertFileExists($countFile);
        $expected = (int) trim((string) file_get_contents($countFile));
        $cases = glob($dir.'/e*.php') ?: [];
        $this->assertGreaterThan(0, $expected);
        $this->assertSame($expected, count($cases));
    }

    public function testStderrEmptyCorpusExitsNonZero(): void
    {
        $tmp = self::$root.'/build/gate-honesty-stderr-empty';
        if (is_dir($tmp)) {
            array_map('unlink', glob($tmp.'/*') ?: []);
            @rmdir($tmp);
        }
        mkdir($tmp, 0777, true);
        $cmd = 'cd '.escapeshellarg(self::$root)
            .' && ./script/differential-sweep.sh --stderr --dir '.escapeshellarg($tmp).' 2>&1';
        exec($cmd, $lines, $exitCode);
        array_map('unlink', glob($tmp.'/*') ?: []);
        @rmdir($tmp);
        $out = implode("\n", $lines);
        $this->assertSame(2, $exitCode, $out);
        $this->assertStringContainsString('empty corpus is not a pass', $out, $out);
    }

    public function testCountFileUsesRunPlusSkip(): void
    {
        $src = (string) file_get_contents(self::$root.'/script/differential-sweep.sh');
        $this->assertStringContainsString('total + skipped', $src);
        $this->assertStringContainsString('--stderr', $src);
    }

    public function testAfterCallChecksUserThrowPending(): void
    {
        $src = (string) file_get_contents(self::$root.'/lib/JIT/TryCatchHelper.php');
        $this->assertStringContainsString('after_call_user_throw', $src);
        $this->assertStringContainsString('phpc_jit_take_throw_pending', $src);
        $this->assertStringContainsString('#36383', $src);
    }
}
