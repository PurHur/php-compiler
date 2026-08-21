<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::setCsvControl/getCsvControl via __spl_csv_* (#33371).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject_setCsvControl / getCsvControl
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectCsvControl33371AotTest extends TestCase
{
    public function testZendAndVmMatch(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_csvcontrol_aot.php';
        $this->assertFileExists($repro);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertStringContainsString('ctrl=[";","\"","\\\\"]', $zend);
        $this->assertStringContainsString('row=["a","b"]', $zend);

        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($repro).' 2>&1',
            $vmOut,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vmOut));
        $this->assertSame($zend, implode("\n", $vmOut)."\n");
    }

    public function testProxiesAndNoNewRuntimeC(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_csvcontrol.c');
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('compileSetCsvControl', $helper);
        $this->assertStringContainsString('compileGetCsvControl', $helper);
        $this->assertStringContainsString('PROP_CSV_SEP', $helper);
        $this->assertStringContainsString('#33371', $helper);
        $call = (string) file_get_contents($root.'/lib/JIT/Call/SplFileObjectMethod.php');
        $this->assertStringContainsString("'setcsvcontrol'", $call);
        $this->assertStringContainsString("'getcsvcontrol'", $call);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString("'setCsvControl', 'getCsvControl'", $ctx);
        $obj = (string) file_get_contents($root.'/lib/JIT/Builtin/Type/Object_.php');
        $this->assertStringContainsString('PROP_CSV_SEP', $obj);
        $this->assertStringContainsString('PROP_CSV_ENC', $obj);
        $this->assertStringContainsString('PROP_CSV_ESC', $obj);
    }
}
