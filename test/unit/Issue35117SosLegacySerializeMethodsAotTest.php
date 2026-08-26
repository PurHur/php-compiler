<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplObjectStorage::serialize()/unserialize() legacy x:/m: (#35117).
 *
 * #35116 wired ArrayObject/dllist; SOS method path remained silent-null (#579).
 *
 * php-src: ext/spl/spl_observer.c
 *
 * @group llvm
 * @group aot
 */
final class Issue35117SosLegacySerializeMethodsAotTest extends TestCase
{
    public function testProxiesRegistered(): void
    {
        $root = dirname(__DIR__, 2);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString('splobjectstorage::serialize', $ctx);
        $this->assertStringContainsString('splobjectstorage::unserialize', $ctx);
        $sos = (string) file_get_contents($root.'/lib/VM/SplObjectStorageJitHelper.php');
        $this->assertStringContainsString('compileLegacySerialize', $sos);
        $this->assertStringContainsString('UnserializeSplObjectStorageLegacyNestedJitHelper', $sos);
    }

    public function testAotLegacySerializeMethodsMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_sos_legacy_serialize_methods.php';
        $vmOut = [];
        $vmRc = 0;
        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1',
            $vmOut,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vmOut));
        $expected = implode("\n", $vmOut)."\n";
        $this->assertStringContainsString("empty='x:i:0;m:a:0:{}'\n", $expected);
        $this->assertStringContainsString('cnt=1', $expected);

        $bin = sys_get_temp_dir().'/phpc_issue_35117_sos_'.getmypid().'.bin';
        $compileOut = [];
        $compileRc = 0;
        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1',
            $compileOut,
            $compileRc
        );
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                $runRc = 0;
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($expected, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
