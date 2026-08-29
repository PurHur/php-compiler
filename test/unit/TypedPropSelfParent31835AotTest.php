<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT: bare self/parent typed properties accept valid assigns (#31835 VM follow-up).
 *
 * @group llvm
 * @group aot
 */
final class TypedPropSelfParent31835AotTest extends TestCase
{
    public function testMaintainerGapReproMatchesZend(): void
    {
        $repro = dirname(__DIR__).'/repro/maintainer_gap_prop_type_self.php';
        $this->assertFileExists($repro);
        $zend = $this->zendOutput($repro);
        $this->assertAotOutput($repro, $zend);
    }

    public function testComplianceCaseMatchesZend(): void
    {
        $repro = dirname(__DIR__).'/compliance/cases/language/typed_prop_self_parent_31835.phpt';
        $this->assertFileExists($repro);
        $source = $this->extractFileSection($repro);
        $tmp = sys_get_temp_dir().'/phpc_31835_aot_'.getmypid().'.php';
        file_put_contents($tmp, $source);
        try {
            $zend = $this->zendOutput($tmp);
            $this->assertAotOutput($tmp, $zend);
        } finally {
            @unlink($tmp);
        }
    }

    private function assertAotOutput(string $src, string $expected): void
    {
        $bin = sys_get_temp_dir().'/phpc_31835_aot_'.sha1($src).'_'.getmypid().'.bin';
        $compileCmd = sprintf(
            'php %s -o %s %s 2>&1',
            escapeshellarg(dirname(__DIR__, 2).'/bin/compile.php'),
            escapeshellarg($bin),
            escapeshellarg($src)
        );
        exec($compileCmd, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, "AOT compile failed:\n".implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            exec(escapeshellarg($bin).' 2>&1', $aotOut, $aotRc);
            $this->assertSame(0, $aotRc, "AOT run failed:\n".implode("\n", $aotOut));
            $this->assertSame($expected, implode("\n", $aotOut).([] === $aotOut ? '' : "\n"));
        } finally {
            @unlink($bin);
        }
    }

    private function zendOutput(string $src): string
    {
        $cmd = 'php '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, "Zend run failed:\n".implode("\n", $out));

        return implode("\n", $out).([] === $out ? '' : "\n");
    }

    private function extractFileSection(string $phpt): string
    {
        $raw = (string) file_get_contents($phpt);
        if (!preg_match('/--FILE--\s*\n(.*)\n--EXPECT--/s', $raw, $m)) {
            $this->fail('Could not parse PHPT FILE section: '.$phpt);
        }

        return $m[1];
    }
}
