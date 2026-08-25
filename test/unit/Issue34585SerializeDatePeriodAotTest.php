<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: serialize(DatePeriod) must emit Zend wire, not O:…:0:{} (#34585 / peer #34576).
 *
 * @see php-src ext/date/php_date.c — date_period_object_to_hash / __serialize
 *
 * @group llvm
 * @group aot
 */
final class Issue34585SerializeDatePeriodAotTest extends TestCase
{
    public function testAotMatchesZendFixture(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_34585_serialize_dateperiod_aot.php');
    }

    public function testFoldHelperPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $ser = (string) file_get_contents($root.'/ext/standard/serialize.php');
        $this->assertStringContainsString('compileTimeDatePeriodSerialize', $ser);
        $this->assertStringContainsString('#34585', $ser);
        $jit = (string) file_get_contents($root.'/ext/standard/JitSerialize.php');
        $this->assertStringContainsString('tryFoldDatePeriod', $jit);
        $ctor = (string) file_get_contents($root.'/ext/standard/JitDatePeriodConstruct.php');
        $this->assertStringContainsString('stampCompileTimeSerializeBag', $ctor);
        $support = (string) file_get_contents($root.'/lib/VM/DatePeriodSupport.php');
        $this->assertStringContainsString('encodeZendSerializeWireFromCompileTimeBag', $support);
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out)."\n";
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/ser_34585_'.getmypid().'_'.md5($src);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            $out = [];
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                $runRc = 0;
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $chunk = implode("\n", $runOut)."\n";
                if (0 === $i) {
                    $out = $runOut;
                } else {
                    $this->assertSame(implode("\n", $out)."\n", $chunk, 'run '.($i + 1));
                }
            }

            return implode("\n", $out)."\n";
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
