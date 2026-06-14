<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** fgetcsv() empty row round-trip on native php://memory streams (#5243). */
final class FgetcsvEmptyRowTest extends TestCase
{
    public function testVmFsRoutesNativeCsvThroughVmCsv(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('isNativeCsvStreamHandle', $source);
        $this->assertStringContainsString('fgetcsvNative', $source);
        $this->assertStringContainsString('VmCsv::formatLine', $source);
        $this->assertStringContainsString('VmCsv::parseLine', $source);
    }

    public function testEmptyRowRoundTripMatchesZendShape(): void
    {
        $path = __DIR__.'/../repro-maintainer/parity_fgetcsv_empty_row.php';
        $proc = proc_open(
            ['php', dirname(__DIR__, 2).'/bin/vm.php', $path],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2)
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc));

        $this->assertStringContainsString("array (\n  0 => NULL,\n)", $out);
        $this->assertStringContainsString('not-false', $out);
    }
}
