<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: eval() from instance method inherits $this; static/file scope Errors (#31902).
 *
 * @see php-src Zend/zend_execute.c — ZEND_INCLUDE_OR_EVAL / zend_eval_string
 *
 * @group llvm
 * @group aot
 */
final class EvalInheritsThis31902AotTest extends TestCase
{
    private const EXPECT = "7\n9\nError: Using \$this when not in object context\nfile=Error: Using \$this when not in object context\n";

    public function testVmRepro(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_eval_inherits_this.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'maintainer_gap_eval_inherits_this.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_eval_inherits_this.php';
        $bin = sys_get_temp_dir().'/phpc_aot_eval_this_31902_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php').' -o '
            .escapeshellarg($bin).' '
            .escapeshellarg($src);
        exec($compile.' 2>&1', $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
        @unlink($bin);
        $this->assertSame(0, $runRc, implode("\n", $runOut));
        $this->assertSame(self::EXPECT, implode("\n", $runOut)."\n");
    }
}
