<?php

declare(strict_types=1);

/**
 * Bool dim writes + substr($param, $offset + strlen(...)) for Parsedown (#36380).
 */

use PHPUnit\Framework\TestCase;

final class ParsedownBoolDimAndSubstrHaystack36380Test extends TestCase
{
    public function testBoolDimWritePersistsUnderVm(): void
    {
        $src = dirname(__DIR__) . '/repro/bool_dim_write_isset.php';
        $this->assertFileExists($src);
        $runtime = new \PHPCompiler\Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile((string) file_get_contents($src), 'bool_dim_write_isset.php'));
        $out = (string) ob_get_clean();
        $this->assertStringContainsString("cont_isset=1\n", $out);
        $this->assertStringContainsString("cont_val=1\n", $out);
        $this->assertStringContainsString("code_continue=1\n", $out);
    }

    public function testSubstrParamHaystackBeforeStrlenPlusUnderVm(): void
    {
        $src = dirname(__DIR__) . '/repro/substr_param_vs_local.php';
        $this->assertFileExists($src);
        $runtime = new \PHPCompiler\Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile((string) file_get_contents($src), 'substr_param_vs_local.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("param='b'\nlocal='b'\n", $out);
    }

    public function testHardBreakPreservesFollowingTextUnderVm(): void
    {
        $src = dirname(__DIR__) . '/repro/hard_break_only.php';
        $this->assertFileExists($src);
        $runtime = new \PHPCompiler\Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile((string) file_get_contents($src), 'hard_break_only.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("<p>a<br />\nb</p>", trim($out));
    }
}
