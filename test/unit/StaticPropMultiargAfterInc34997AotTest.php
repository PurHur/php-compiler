<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT/VM: multi-arg shared static read after stmt A::inc()×2 (#34997).
 *
 * php-cfg hoists statement-level StaticCall before StaticPropertyFetch; sibling
 * ARG_SEND rewiring must not bind void StaticCall returns over the fetches.
 *
 * @see php-src Zend/zend_compile.c call-arg evaluation / FETCH_STATIC_PROP_R
 * @see php-src Zend/zend_inheritance.c inherited static storage
 *
 * @group llvm
 * @group aot
 */
final class StaticPropMultiargAfterInc34997AotTest extends TestCase
{
    private const EXPECTED = "int(2)\nint(2)\n";

    public function testVmSharedStaticMultiargAfterInc(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34997_static_prop_multiarg_after_inc.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34997_static_prop_multiarg_after_inc.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotSharedStaticMultiargAfterInc(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34997_static_prop_multiarg_after_inc.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34997_'.getmypid().'.bin';
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
