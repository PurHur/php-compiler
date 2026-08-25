<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * File-scope const holding an enum case — AOT CONST_FETCH (#34783, peer #31967).
 *
 * php-src: Zend/zend_constants.c, Zend/zend_enum.c
 *
 * @group llvm
 */
final class Issue34783FileScopeEnumConstTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — #34783 AOT probes need LLVM');
        }
    }

    public function testIntBackedFileScopeEnumConst(): void
    {
        $this->compileAndRun(
            $this->repoRoot.'/test/repro/issue_34783_file_scope_enum_const.php',
            "1\n"
        );
    }

    public function testStringBackedFileScopeEnumConst(): void
    {
        $this->compileAndRun(
            $this->repoRoot.'/test/repro/issue_34783_file_scope_string_enum_const.php',
            "hi\n"
        );
    }

    public function testUnitFileScopeEnumConst(): void
    {
        $this->compileAndRun(
            $this->repoRoot.'/test/repro/issue_34783_file_scope_unit_enum_const.php',
            "A\n"
        );
    }

    public function testClassConstControlStillGreen(): void
    {
        $this->compileAndRun(
            $this->repoRoot.'/test/repro/issue_31967_enum_class_const.php',
            'h'
        );
    }

    private function compileAndRun(string $path, string $expected): void
    {
        $this->assertFileExists($path);
        $outBin = sys_get_temp_dir().'/issue_34783_'.md5($path).'.bin';
        $cmd = sprintf(
            'php %s -o %s %s 2>&1',
            escapeshellarg($this->repoRoot.'/bin/compile.php'),
            escapeshellarg($outBin),
            escapeshellarg($path)
        );
        exec($cmd, $compileOut, $crc);
        $this->assertSame(0, $crc, implode("\n", $compileOut));
        $this->assertFileExists($outBin);
        exec(escapeshellarg($outBin).' 2>&1', $runOut, $arc);
        $this->assertSame(0, $arc, implode("\n", $runOut));
        $this->assertSame(rtrim($expected, "\n"), implode("\n", $runOut));
        @unlink($outBin);
    }
}
