<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: `C::$s.='!'` must persist across calls (#32899).
 *
 * Peer of function-static concat (#32889): dead-operand CONCAT into a
 * STATIC_PROPERTY_FETCH temp must {@see JIT::assignOperand} via
 * staticPropertyGlobal — not ephemeral entry-alloca.
 *
 * @see php-src Zend/zend_execute.c ZEND_ASSIGN_OP on static property CVs
 *
 * @group llvm
 * @group aot
 */
final class StaticPropStringConcat32899AotTest extends TestCase
{
    private const EXPECTED = "hi!hi!!\nhi!\n";

    public function testVmStaticPropStringConcat(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32899_static_prop_string_concat.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32899_static_prop_string_concat.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotStaticPropStringConcat(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32899_static_prop_string_concat.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32899_spsc_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
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
