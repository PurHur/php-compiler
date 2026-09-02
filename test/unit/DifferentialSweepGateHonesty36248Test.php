<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Gate honesty: empty differential corpus and missing rg must not read as green (#36248). */
final class DifferentialSweepGateHonesty36248Test extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testCountFileMatchesBundledCorpus(): void
    {
        $countFile = self::$root.'/test/differential/COUNT';
        $this->assertFileExists($countFile);
        $expected = (int) trim((string) file_get_contents($countFile));
        $cases = glob(self::$root.'/test/differential/cases/*.php') ?: [];
        $this->assertGreaterThan(0, $expected);
        $this->assertSame($expected, count($cases));
    }

    public function testEmptyCorpusExitsNonZero(): void
    {
        $tmp = self::$root.'/build/gate-honesty-phpunit-empty';
        if (is_dir($tmp)) {
            array_map('unlink', glob($tmp.'/*') ?: []);
            rmdir($tmp);
        }
        mkdir($tmp, 0777, true);
        $cmd = 'cd '.escapeshellarg(self::$root)
            .' && ./script/differential-sweep.sh --dir '.escapeshellarg($tmp).' 2>&1';
        exec($cmd, $lines, $exitCode);
        array_map('unlink', glob($tmp.'/*') ?: []);
        @rmdir($tmp);
        $out = implode("\n", $lines);
        $this->assertSame(2, $exitCode, $out);
        $this->assertStringContainsString('empty corpus is not a pass', $out, $out);
    }

    public function testGateHonestyScriptPassesOnMaster(): void
    {
        $cmd = 'cd '.escapeshellarg(self::$root).' && ./script/check-gate-honesty.sh 2>&1';
        exec($cmd, $lines, $exitCode);
        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('all probes passed', $out, $out);
    }
}
