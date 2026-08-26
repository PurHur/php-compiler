<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPUnit\Framework\TestCase;

/**
 * #35017 — AOT importNode(documentElement->firstElementChild) copies the element
 * (leftover of #33918 firstChild-only fix).
 *
 * @group llvm
 */
final class Issue35017DomImportNodeFirstElementChildAotTest extends TestCase
{
    public function testAotImportNodeFirstElementChildMatchesZend(): void
    {
        $src = dirname(__DIR__).'/repro/aot_dom_importnode_firstelementchild.php';
        $this->assertFileExists($src);

        $zend = $this->runPhp($src);
        $vm = $this->runVm($src);
        $this->assertSame($zend, $vm, 'VM must match Zend');
        $this->assertStringContainsString('viaFEC=a', $zend);
        $this->assertStringContainsString('<r><b/><a/></r>', $zend);

        $bin = sys_get_temp_dir().'/phpc_35017_'.getmypid().'.bin';
        $compile = $this->runCompile($src, $bin);
        $this->assertSame(0, $compile['code'], "AOT compile failed:\n".$compile['out']);
        $aot = $this->runBin($bin);
        @unlink($bin);

        $this->assertSame($zend, $aot['out'], 'AOT must match Zend importNode(firstElementChild)');
        $this->assertSame(0, $aot['code']);
    }

    public function testAotImportNodeAssignedFirstElementChildMatchesZend(): void
    {
        $src = sys_get_temp_dir().'/phpc_35017_var_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
$d1 = new DOMDocument();
$d1->loadXML('<src><a/></src>');
$d2 = new DOMDocument();
$d2->loadXML('<r><b/></r>');
$el = $d1->documentElement->firstElementChild;
$imp = $d2->importNode($el, true);
echo 'viaVar=', $imp->tagName, "\n";
$d2->documentElement->appendChild($imp);
echo $d2->saveXML($d2->documentElement), "\n";
PHP);
        try {
            $zend = $this->runPhp($src);
            $bin = sys_get_temp_dir().'/phpc_35017_var_'.getmypid().'.bin';
            $compile = $this->runCompile($src, $bin);
            $this->assertSame(0, $compile['code'], "AOT compile failed:\n".$compile['out']);
            $aot = $this->runBin($bin);
            @unlink($bin);
            $this->assertSame($zend, $aot['out']);
        } finally {
            @unlink($src);
        }
    }

    /** @return array{code:int,out:string} */
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
