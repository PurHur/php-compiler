<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: `$this->o->x = 1` after `$this->o = new stdClass` must compile (#34395).
 *
 * Generic CFG type `object` used to recurse in tryPropertyFetchByRuntimeClass until OOM.
 *
 * @see php-src Zend/zend_object_handlers.c zend_std_write_property
 *
 * @group llvm
 * @group aot
 */
final class ObjectTypedStdclassDyn34395AotTest extends TestCase
{
    public function testVmObjectTypedStdclassDynMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34395_object_typed_stdclass_dyn.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34395_object_typed_stdclass_dyn.php'));
        $this->assertSame("1\n", (string) ob_get_clean());
    }

    public function testVmInheritedCtorStdclassDynMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34395_object_typed_stdclass_dyn_inherit.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34395_object_typed_stdclass_dyn_inherit.php'));
        $this->assertSame("1\n", (string) ob_get_clean());
    }

    public function testAotObjectTypedStdclassDynMatchesZend(): void
    {
        $this->assertAotRepro(
            'issue_34395_object_typed_stdclass_dyn.php',
            "1\n"
        );
    }

    public function testAotInheritedCtorStdclassDynMatchesZend(): void
    {
        $this->assertAotRepro(
            'issue_34395_object_typed_stdclass_dyn_inherit.php',
            "1\n"
        );
    }

    private function assertAotRepro(string $reproFile, string $expect): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/'.$reproFile;
        $bin = sys_get_temp_dir().'/phpc_issue_34395_'.getmypid().'_'.str_replace('.', '_', $reproFile).'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($expect, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
