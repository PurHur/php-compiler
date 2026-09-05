<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Orphan prune flag on emit-helper-runtime-object (#36401 / #25377).
 */
final class HelperRuntimeOrphanPruneTest extends TestCase
{
    public function testEmitScriptDocumentsOrphanPruneFlag(): void
    {
        $path = dirname(__DIR__, 2) . '/script/emit-helper-runtime-object.php';
        $this->assertFileExists($path);
        $body = (string) file_get_contents($path);
        $this->assertStringContainsString('--prelink-orphans-only', $body);
        $this->assertStringContainsString('#25377', $body);
        $this->assertStringContainsString('live fingerprint-stale kept', $body);
    }

    public function testMathAbsHelperPathRetiredButPeerInstanceOfStillLive(): void
    {
        $root = dirname(__DIR__, 2);
        $abs = $root . '/ext/standard/AbsJitHelper.php';
        $this->assertFileExists($abs);
        $absCode = (string) file_get_contents($abs);
        $this->assertStringNotContainsString('JitVmHelperLink::', $absCode);

        $peer = $root . '/lib/JIT/InstanceOfHelper.php';
        $this->assertFileExists($peer);
        $peerCode = (string) file_get_contents($peer);
        $this->assertStringContainsString('JitVmHelperLink::', $peerCode);
        $this->assertStringContainsString('/VM/InstanceOfJitHelper.php', $peerCode);
    }

    public function testMakefileWiresOrphanPruneTarget(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2) . '/Makefile');
        $this->assertStringContainsString('helper-runtime-orphan-prune:', $body);
        $this->assertStringContainsString('--prelink-orphans-only', $body);
    }
}
