<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPUnit\Framework\TestCase;

/**
 * #35131 — AOT getAttributeNodeNS after a second loadXML uses source document XML.
 *
 * @group llvm
 */
final class Issue35131DomGetAttributeNodeNSCrossDocAotTest extends TestCase
{
    public function testAotGetAttributeNodeNSCrossDocMatchZend(): void
    {
        $src = dirname(__DIR__).'/repro/aot_dom_getattrnodens_cross_doc.php';
        $this->assertFileExists($src);

        $zend = $this->runPhp($src);
        $vm = $this->runVm($src);
        $this->assertSame($zend, $vm, 'VM must match Zend');
        $this->assertStringContainsString('type=2 name=p:a val=1', $zend);
        $this->assertStringContainsString('imp_type=2 imp_name=p:a imp_val=1', $zend);
        $this->assertStringContainsString('single=2|p:a|1', $zend);

        $bin = sys_get_temp_dir().'/phpc_35131_'.getmypid().'.bin';
        $compile = $this->runCompile($src, $bin);
        $this->assertSame(0, $compile['code'], "AOT compile failed:\n".$compile['out']);
        $aot = $this->runBin($bin);
        @unlink($bin);

        $this->assertSame($zend, $aot['out'], 'AOT must match Zend getAttributeNodeNS cross-doc');
        $this->assertSame(0, $aot['code']);
    }

    private function runPhp(string $src): string
    {
        $cmd = 'php '.escapeshellarg($src).' 2>&1';
        exec($cmd, $lines, $code);
        $this->assertSame(0, $code, "Zend failed:\n".implode("\n", $lines));

        return implode("\n", $lines)."\n";
    }

    private function runVm(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'php '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $lines, $code);
        $this->assertSame(0, $code, "VM failed:\n".implode("\n", $lines));

        return implode("\n", $lines)."\n";
    }

    /** @return array{code:int,out:string} */
    private function runCompile(string $src, string $bin): array
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'php '.escapeshellarg($root.'/bin/compile.php').' -o '.escapeshellarg($bin)
            .' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $lines, $code);

        return ['code' => $code, 'out' => implode("\n", $lines)];
    }

    /** @return array{code:int,out:string} */
    private function runBin(string $bin): array
    {
        exec(escapeshellarg($bin).' 2>&1', $lines, $code);

        return ['code' => $code, 'out' => implode("\n", $lines).([] === $lines ? '' : "\n")];
    }
}
