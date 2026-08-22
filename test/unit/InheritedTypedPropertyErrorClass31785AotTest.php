<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: inherited typed property Error cites declaring class (#31785).
 *
 * @see php-src Zend/zend_object_handlers.c prop_info->ce
 *
 * @group llvm
 * @group aot
 */
final class InheritedTypedPropertyErrorClass31785AotTest extends TestCase
{
    private const EXPECTED = "x=msg=Typed property A::\$x must not be accessed before initialization\n"
        ."after\n";

    public function testVmInheritedTypedPropertyErrorUsesDeclaringClass(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_inherited_typed_error_class.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'maintainer_gap_inherited_typed_error_class.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotInheritedTypedPropertyErrorUsesDeclaringClass(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_inherited_typed_error_class.php';
        $bin = sys_get_temp_dir().'/phpc_issue_31785_'.getmypid().'.bin';
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
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
