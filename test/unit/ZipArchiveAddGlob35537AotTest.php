<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ZipArchive::addGlob / addPattern NestedJIT (#35537 leftover of #35531).
 *
 * @group aot-lint
 */
final class ZipArchiveAddGlob35537AotTest extends TestCase
{
    public function testAddGlobAndAddPatternBoundInContext(): void
    {
        $ctx = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Context.php');
        $this->assertStringContainsString("'addGlob'", $ctx);
        $this->assertStringContainsString("'addPattern'", $ctx);
    }

    public function testZipArchiveMethodDispatchesAddGlob(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Call/ZipArchiveMethod.php');
        $this->assertStringContainsString("'addglob'", $src);
        $this->assertStringContainsString("'addpattern'", $src);
        $this->assertStringContainsString('JitZipArchive::addGlob', $src);
        $this->assertStringContainsString('JitZipArchive::addPattern', $src);
    }

    public function testHelperHasAgApAgpOps(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/zip/ZipArchiveJitHelper.php');
        $this->assertStringContainsString("'ag' === \$op", $src);
        $this->assertStringContainsString("'ap' === \$op", $src);
        $this->assertStringContainsString("'agp' === \$op", $src);
        $this->assertStringContainsString('ADDPATHS_FALSE_RC', $src);
    }
}
