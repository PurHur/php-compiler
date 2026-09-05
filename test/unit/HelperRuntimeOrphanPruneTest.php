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

    public function testEmitScriptRefusesGcSectionsMixWithoutMigration(): void
    {
        $path = dirname(__DIR__, 2) . '/script/emit-helper-runtime-object.php';
        $body = (string) file_get_contents($path);
        $this->assertStringContainsString('--migrate-to-gc-sections', $body);
        $this->assertStringContainsString('AotGcSections::ENV', $body);
        $this->assertStringContainsString('monolithic prelinked corpus', $body);
        $this->assertStringContainsString('refusePrelinkGcMixReason', $body);
        $this->assertStringContainsString('aborting before rewriting arch manifest (#36401)', $body);
    }

    public function testMakefileDocumentsGcSectionsPrelinkGuard(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2) . '/Makefile');
        $this->assertStringContainsString('--migrate-to-gc-sections', $body);
        $this->assertStringContainsString('monolithic', $body);
    }

    public function testMonolithicCommittedUnitAllowedForPrelink(): void
    {
        $dir = \PHPCompiler\AOT\HelperRuntimeCache::prelinkedUnitsDir()
            . '/' . \PHPCompiler\AOT\HelperRuntimeCache::slugFor('/ext/ctype/CtypeJitHelper.php');
        $object = $dir . '/unit.o';
        if (!is_file($object)) {
            $this->markTestSkipped('ctype helper unit.o missing from prelinked cache');
        }
        if (\PHPCompiler\AOT\HelperRuntimeCache::prelinkedCorpusHasGcSections()) {
            $this->markTestSkipped('prelinked corpus already migrated to gc_sections');
        }
        $this->assertNull(
            \PHPCompiler\AOT\HelperRuntimeCache::refusePrelinkGcMixReason($object, false),
            'monolithic committed unit.o must remain publishable'
        );
    }
}
