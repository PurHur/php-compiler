<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @covers issue #2543 */
final class BootstrapPhaseADeferredTest extends TestCase
{
    public function testStringPregMatchIsIncludedNotRatioDeferred(): void
    {
        require_once dirname(__DIR__, 2).'/script/bootstrap-phase-a-deferred.php';

        $deferred = bootstrap_phase_a_ratio_deferred_paths();
        $this->assertSame([], $deferred, 'no ratio-deferred paths after #2543');
        $this->assertNotContains('lib/JIT/Builtin/StringPregMatch.php', $deferred);
        $this->assertNotContains('lib/AOT/Linker.php', $deferred);
        $this->assertNotContains('lib/VM/HashTable.php', $deferred);
    }

    public function testVmHashTableInSpineSmokeBundle(): void
    {
        $spineMain = dirname(__DIR__, 2).'/test/selfhost/compiler_lib_spine_smoke/main.php';
        $contents = (string) file_get_contents($spineMain);
        $this->assertStringContainsString('lib/VM/HashTable.php', $contents);
        $this->assertStringContainsString('lib/JIT/TryCatchState.php', $contents);
    }

    public function testStringPregMatchInSpineSmokeBundle(): void
    {
        $spineMain = dirname(__DIR__, 2).'/test/selfhost/compiler_lib_spine_smoke/main.php';
        $this->assertFileExists($spineMain);
        $contents = (string) file_get_contents($spineMain);
        $this->assertStringContainsString('lib/JIT/Builtin/StringPregMatch.php', $contents);
    }

    public function testInventoryReportPhaseACounts(): void
    {
        require_once dirname(__DIR__, 2).'/script/bootstrap-lib.php';

        $root = dirname(__DIR__, 2);
        $report = bootstrapCollectInventoryReport($root);
        $this->assertArrayHasKey('phase_a', $report);
        $this->assertSame($report['totals']['files'], $report['phase_a']['vm_path_files']);
        $this->assertGreaterThan(0, $report['phase_a']['phase_a_inventory_files']);
        $this->assertLessThanOrEqual(
            $report['totals']['files'],
            $report['phase_a']['phase_a_inventory_files'] + $report['phase_a']['phase_a_ratio_deferred']
        );
    }
}
