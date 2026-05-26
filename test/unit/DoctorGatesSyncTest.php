<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Doctor ↔ ci-defaults gate drift guard (issue #2380).
 */
final class DoctorGatesSyncTest extends TestCase
{
    public function testDoctorGatesSyncPassesOnMaster(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/check-doctor-gates-sync.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testTrackedGatePatternSkipsNorthStarPresenterGates(): void
    {
        require_once dirname(__DIR__, 2).'/script/doctor-gates-sync-lib.php';

        $this->assertFalse(doctor_gates_sync_tracked_gate('NORTH_STAR2_VERIFY_GATE'));
        $this->assertTrue(doctor_gates_sync_tracked_gate('MINIWEBAPP_WEB_SMOKE_GATE'));
        $this->assertTrue(doctor_gates_sync_tracked_gate('WAVE3_ROADMAP_SYNC_GATE'));
    }

    public function testMissingGateFailsChecker(): void
    {
        require_once dirname(__DIR__, 2).'/script/doctor-gates-sync-lib.php';

        $errors = doctor_gates_sync_missing_gate_errors(
            dirname(__DIR__, 2),
            ['ZZZ_EXAMPLE_MISSING_GATE'],
            doctorBody: '',
            miniwebappGatesBody: '',
            matrixBody: '',
            allowlist: [],
        );
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('ZZZ_EXAMPLE_MISSING_GATE', $errors[0]);
    }
}
