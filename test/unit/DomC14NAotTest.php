<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT DOMNode::C14N() must return canonical string, not object (#32962).
 *
 * @group llvm
 */
final class DomC14NAotTest extends TestCase
{
    public function testDocumentAndDocumentElementC14NMatchZend(): void
    {
        $src = __DIR__.'/../repro/issue_32962_dom_c14n_aot.php';
        $zend = $this->runPhp($src);
        $vm = $this->runVm($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $vm);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString('string|', $aot);
        $this->assertStringNotContainsString('object|', $aot);
        $this->assertStringContainsString('<r a="1"><c></c></r>', $aot);
    }

    public function testRelativeNamespaceStillFalse(): void
    {
        $src = __DIR__.'/../repro/issue_32962_dom_c14n_relative_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertSame('rel|abs', $aot);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runVm(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/dom_c14n_aot_'.getmypid();
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $compOut, $compRc);
        $this->assertSame(0, $compRc, implode("\n", $compOut));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $out, $rc);
        @unlink($bin);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }
}
