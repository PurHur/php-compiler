<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPUnit\Framework\TestCase;

/**
 * #35106 — AOT XMLReader::XML()+read() must not SIGSEGV on $reader->name.
 *
 * @group llvm
 */
final class Issue35106XmlReaderReadNameAotTest extends TestCase
{
    public function testAotXmlReaderInstanceXmlReadNameMatchesZend(): void
    {
        $src = dirname(__DIR__).'/repro/aot_xmlreader_read_name.php';
        $this->assertFileExists($src);

        $zend = $this->runPhp($src);
        $vm = $this->runVm($src);
        $this->assertSame($zend, $vm, 'VM must match Zend');
        $this->assertSame("r|a|", trim($zend));

        $bin = sys_get_temp_dir().'/phpc_35106_'.getmypid().'.bin';
        $compile = $this->runCompile($src, $bin);
        $this->assertSame(0, $compile['code'], "AOT compile failed:\n".$compile['out']);
        $aot = $this->runBin($bin);
        @unlink($bin);

        $this->assertSame($zend, $aot['out'], 'AOT must match Zend XMLReader::XML+read+name');
        $this->assertSame(0, $aot['code']);
    }

    private function runPhp(string $src): string
    {
        exec('php '.escapeshellarg($src).' 2>&1', $lines, $code);
        $this->assertSame(0, $code, "Zend failed:\n".implode("\n", $lines));

        return implode("\n", $lines).([] === $lines ? '' : "\n");
    }

    private function runVm(string $src): string
    {
        $root = dirname(__DIR__, 2);
        exec('php '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($src).' 2>&1', $lines, $code);
        $this->assertSame(0, $code, "VM failed:\n".implode("\n", $lines));

        return implode("\n", $lines).([] === $lines ? '' : "\n");
    }

    /** @return array{code:int,out:string} */
    private function runCompile(string $src, string $bin): array
    {
        $root = dirname(__DIR__, 2);
        exec(
            'php '.escapeshellarg($root.'/bin/compile.php').' -o '.escapeshellarg($bin)
            .' '.escapeshellarg($src).' 2>&1',
            $lines,
            $code
        );

        return ['code' => $code, 'out' => implode("\n", $lines)];
    }

    /** @return array{code:int,out:string} */
    private function runBin(string $bin): array
    {
        exec(escapeshellarg($bin).' 2>&1', $lines, $code);

        return ['code' => $code, 'out' => implode("\n", $lines).([] === $lines ? '' : "\n")];
    }
}
