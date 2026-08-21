<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject::fscanf by-ref assign via live stream handle (#33389).
 *
 * @see php-src ext/spl/spl_directory.c zim_SplFileObject_fscanf
 *
 * @group llvm
 * @group aot
 */
final class SplFileObjectFscanfByRef33389AotTest extends TestCase
{
    public function testZendOutput(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/splfileobject_fscanf_byref_aot.php';
        $this->assertFileExists($repro);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertStringContainsString('n=2 a=7 b=x', $zend);
        $this->assertStringContainsString('eof=-1', $zend);
    }

    public function testProxiesAndAssignAbi(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/runtime/spl_fileobject_fscanf_byref.c');
        $helper = (string) file_get_contents($root.'/lib/VM/SplFileObjectJitHelper.php');
        $this->assertStringContainsString('#33389', $helper);
        $this->assertStringContainsString('invokeAssign', $helper);
        $apply = (string) file_get_contents($root.'/lib/JIT/Builtin/SscanfSimpleArrayApply.php');
        $this->assertStringContainsString('phpc_sscanf_simple_assign', $apply);
        $this->assertStringContainsString('invokeAssign', $apply);
    }
}
