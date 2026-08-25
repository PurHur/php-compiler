<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: ChildNode after/before/replaceWith(string) inserts text like Zend (#34760).
 *
 * @group llvm
 * @group aot
 */
final class DomChildNodeStringArg34760AotTest extends TestCase
{
    private const EXPECTED = <<<'TXT'
after_assigned=<r><a/>x</r>
after_chained=<r><a/>y</r>
before=<r>b<a/></r>
replaceWith=<r>z</r>
TXT;

    public function testAotChildNodeStringArgMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34760_dom_childnode_string_arg_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_cn_str_34760_'.getmypid().'.bin';
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
            $this->assertSame(self::EXPECTED."\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
