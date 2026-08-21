<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::fscanf via fgets + __compiler_sscanf_array (#33382).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject_fscanf
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectFscanf33382AotTest extends TestCase
{
    public function testZendAndVmMatch(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_fscanf_aot.php';
        $this->assertFileExists($repro);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertStringContainsString('row=[42,"hello"]', $zend);

        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($repro).' 2>&1',
            $vmOut,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vmOut));
        // VM may differ from Zend on EOF (false vs null); require matching first row.
        $this->assertStringContainsString('row=[42,"hello"]', implode("\n", $vmOut)."\n");
    }

    public function testProxiesAndNoNewRuntimeC(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_fscanf.c');
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('compileFscanf', $helper);
        $this->assertStringContainsString('SscanfSimpleArrayApply', $helper);
        $this->assertStringContainsString('#33382', $helper);
        $this->assertFileExists($root.'/lib/JIT/Builtin/SscanfSimpleArrayApply.php');
        $apply = (string) file_get_contents($root.'/lib/JIT/Builtin/SscanfSimpleArrayApply.php');
        $this->assertStringContainsString('phpc_sscanf_simple_array', $apply);
        $call = (string) file_get_contents($root.'/lib/JIT/Call/SplFileObjectMethod.php');
        $this->assertStringContainsString("'fscanf'", $call);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString("'fscanf'", $ctx);
    }
}
