<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: DocumentFragment firstChild/lastChild after expand (#35518 re-#35461).
 *
 * php-src: ext/dom/node.c — dom_node_append_child fragment expand.
 *
 * @group llvm
 * @group aot
 */
final class DomFragmentFirstChildAfterExpand35518AotTest extends TestCase
{
    public function testFragmentFirstChildAfterExpandMatchesZend(): void
    {
        $src = __DIR__.'/../repro/aot_dom_fragment_firstchild_after_expand.php';
        $zend = $this->runPhp($src);
        $vm = $this->runVm($src);
        $this->assertSame($zend, $vm, 'VM must match Zend');
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot, 'AOT must match Zend');
    }

    public function testFragmentFirstChildAfterExpandRepeated(): void
    {
        $src = __DIR__.'/../repro/aot_dom_fragment_firstchild_after_expand.php';
        $zend = $this->runPhp($src);
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/dom_frag_empty_rep_35518_'.getmypid();
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $compOut, $compRc);
        $this->assertSame(0, $compRc, implode("\n", $compOut));
        for ($i = 0; $i < 5; ++$i) {
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, "run $i: ".implode("\n", $out));
            $this->assertSame($zend, implode("\n", $out), "run $i output");
            $out = [];
        }
        @unlink($bin);
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
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php')
            .' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/dom_frag_empty_35518_'.getmypid().'_'.md5($src);
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
