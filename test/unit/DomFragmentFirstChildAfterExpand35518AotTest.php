<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DocumentFragment firstChild/lastChild null after parent appendChild expand (#35518).
 *
 * @see php-src ext/dom/node.c dom_node_append_child fragment expand
 *
 * @group llvm
 * @group aot
 */
final class DomFragmentFirstChildAfterExpand35518AotTest extends TestCase
{
    public function testFragmentEdgesNullAfterExpandNoSegfault(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $src = __DIR__.'/../repro/aot_dom_fragment_firstchild_after_expand.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testSeedSkipsNullChildEdge(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/ext/dom/JitDomNodeChildProperty.php');
        $this->assertStringContainsString('TYPE_NULL', $src);
        $this->assertStringContainsString('dom_child_seed_ok', $src);
        $this->assertStringContainsString('#35518', $src);
    }

    private function runVm(string $src): string
    {
        return $this->runBin('bin/vm.php', $src);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/dom_frag_exp_'.getmypid().'_'.md5($src);
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php').' -o '
            .escapeshellarg($bin).' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($compile.' 2>&1', $cout, $crc);
            $this->assertSame(0, $crc, implode("\n", $cout));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            @unlink($bin);
            chdir($cwd);
        }
    }

    private function runBin(string $binRel, string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/'.$binRel).' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
        }
    }
}
