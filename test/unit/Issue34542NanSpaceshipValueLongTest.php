<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT compile+execute: boxed NAN/float <=> int (#34542, leftover of #31967).
 *
 * php-src: Zend/zend_operators.c compare_function — IS_DOUBLE vs IS_LONG promotes long.
 *
 * @group llvm
 */
final class Issue34542NanSpaceshipValueLongTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — #34542 AOT spaceship needs LLVM');
        }
    }

    public function testBoxedNanSpaceshipIntMatchesZend(): void
    {
        $src = $this->repoRoot.'/test/repro/issue_34542_nan_spaceship_value_long.php';
        $bin = sys_get_temp_dir().'/issue_34542_nan_sp_'.getmypid().'.bin';
        $cmd = sprintf(
            'cd %s && php bin/compile.php -o %s %s 2>&1',
            escapeshellarg($this->repoRoot),
            escapeshellarg($bin),
            escapeshellarg($src)
        );
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, "compile failed:\n".implode("\n", $out));
        $this->assertFileExists($bin);

        $aot = [];
        exec(escapeshellarg($bin).' 2>&1', $aot, $arc);
        @unlink($bin);
        $this->assertSame(0, $arc, "aot run failed:\n".implode("\n", $aot));

        $zend = [];
        exec('php '.escapeshellarg($src).' 2>&1', $zend, $zrc);
        $this->assertSame(0, $zrc);
        $this->assertSame($zend, $aot, 'AOT output must match Zend');
    }
}
