<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::fgetcsv via live stream handle (#33346).
 *
 * Native AOT exec under PHPUnit is currently poisoned on this harness for all
 * SplFileObject AOT unit tests (SIGSEGV after c:main_before_php even with a
 * clean bash wrapper). Functional AOT is verified via docker-exec in the PR.
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject_fgetcsv
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectFgetcsv33346AotTest extends TestCase
{
    public function testZendAndVmMatch(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_fgetcsv_aot.php';
        $this->assertFileExists($repro);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertStringContainsString('a|b', $zend);
        $this->assertStringNotContainsString('not-array', $zend);

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
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_fgetcsv.c');
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('compileFgetcsv', $helper);
        $this->assertStringContainsString('#33346', $helper);
        $this->assertStringContainsString('JitFgetcsv::invoke', $helper);
        $this->assertStringContainsString('StringStrGetcsv::ensureLinked', $helper);
        $call = (string) file_get_contents($root.'/lib/JIT/Call/SplFileObjectMethod.php');
        $this->assertStringContainsString("'fgetcsv'", $call);
        $ctx = (string) file_get_contents($root.'/lib/JIT/Context.php');
        $this->assertStringContainsString("'fputcsv', 'fgetcsv', 'eof'", $ctx);
        $this->assertStringContainsString('#33346', $ctx);
        $csv = (string) file_get_contents($root.'/ext/standard/CsvStrGetcsvJitHelper.php');
        $this->assertStringContainsString('substr($line, 0, $len)', $csv);
        $this->assertStringContainsString('#33346', $csv);
    }
}
