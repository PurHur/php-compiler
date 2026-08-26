<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPUnit\Framework\TestCase;

/**
 * #35021 — AOT importNode via next/previousElementSibling and nextSibling
 * (leftover of #35017 FEC/LEC-only stamps).
 *
 * @group llvm
 */
final class Issue35021DomImportNodeElementSiblingAotTest extends TestCase
{
    public function testAotImportNodeElementSiblingMatchesZend(): void
    {
        $src = dirname(__DIR__).'/repro/aot_dom_importnode_element_sibling.php';
        $this->assertFileExists($src);

        $zend = $this->runPhp($src);
        $vm = $this->runVm($src);
        $this->assertSame($zend, $vm, 'VM must match Zend');
        $this->assertStringContainsString('viaNES=b', $zend);
        $this->assertStringContainsString('viaPES=a', $zend);
        $this->assertStringContainsString('viaNS=b', $zend);

        $bin = sys_get_temp_dir().'/phpc_35021_'.getmypid().'.bin';
        $compile = $this->runCompile($src, $bin);
        $this->assertSame(0, $compile['code'], "AOT compile failed:\n".$compile['out']);
        $aot = $this->runBin($bin);
        @unlink($bin);

        $this->assertSame($zend, $aot['out'], 'AOT must match Zend importNode sibling edges');
        $this->assertSame(0, $aot['code']);
    }

    public function testAotImportNodeAssignedNextElementSiblingMatchesZend(): void
    {
        $src = sys_get_temp_dir().'/phpc_35021_var_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
$d1 = new DOMDocument();
$d1->loadXML('<src><a/><b/></src>');
$d2 = new DOMDocument();
$d2->loadXML('<r><c/></r>');
$el = $d1->documentElement->firstElementChild->nextElementSibling;
$imp = $d2->importNode($el, true);
echo 'viaVar=', $imp->tagName, "\n";
$d2->documentElement->appendChild($imp);
echo $d2->saveXML($d2->documentElement), "\n";
PHP);
        try {
            $zend = $this->runPhp($src);
            $bin = sys_get_temp_dir().'/phpc_35021_var_'.getmypid().'.bin';
            $compile = $this->runCompile($src, $bin);
            $this->assertSame(0, $compile['code'], "AOT compile failed:\n".$compile['out']);
            $aot = $this->runBin($bin);
            @unlink($bin);
            $this->assertSame($zend, $aot['out']);
        } finally {
            @unlink($src);
        }
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
