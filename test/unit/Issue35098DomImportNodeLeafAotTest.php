<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPUnit\Framework\TestCase;

/**
 * #35098 — AOT importNode(Comment/CDATA/PI) copies leaf nodes (leftover of #35043).
 *
 * @group llvm
 */
final class Issue35098DomImportNodeLeafAotTest extends TestCase
{
    public function testAotImportNodeLeafNodesMatchZend(): void
    {
        $src = dirname(__DIR__).'/repro/aot_dom_importnode_leaf_nodes.php';
        $this->assertFileExists($src);

        $zend = $this->runPhp($src);
        $vm = $this->runVm($src);
        $this->assertSame($zend, $vm, 'VM must match Zend');
        foreach ([
            'create_comment_type=8',
            'create_comment_xml=<r><!--c--></r>',
            'create_cdata_type=4',
            'create_cdata_xml=<r><![CDATA[x]]></r>',
            'create_pi_type=7',
            'create_pi_xml=<r><?pi data?></r>',
            'sib_comment_type=8',
            'sib_comment_xml=<r><!--c--></r>',
            'sib_cdata_type=4',
            'sib_cdata_xml=<r><![CDATA[x]]></r>',
            'sib_pi_type=7',
            'sib_pi_xml=<r><?pi data?></r>',
        ] as $needle) {
            $this->assertStringContainsString($needle, $zend);
        }

        $bin = sys_get_temp_dir().'/phpc_35098_'.getmypid().'.bin';
        $compile = $this->runCompile($src, $bin);
        $this->assertSame(0, $compile['code'], "AOT compile failed:\n".$compile['out']);
        $aot = $this->runBin($bin);
        @unlink($bin);

        $this->assertSame($zend, $aot['out'], 'AOT must match Zend importNode(Comment/CDATA/PI)');
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
