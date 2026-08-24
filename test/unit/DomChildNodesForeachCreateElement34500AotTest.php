<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: foreach createElement-only childNodes — live snapshot without loadXML (#34500).
 *
 * php-src: ext/dom/nodelist.c InternalIterator
 *
 * @group llvm
 * @group aot
 */
final class DomChildNodesForeachCreateElement34500AotTest extends TestCase
{
    private const EXPECTED = "a,b,c,\na,x,c,\n";

    public function testVmMatchesZend(): void
    {
        $this->assertSame(self::EXPECTED, $this->runVm());
    }

    public function testAotMatchesZend(): void
    {
        $this->assertSame(self::EXPECTED, $this->runAot());
    }

    private function runVm(): string
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34500_dom_childnodes_foreach_createelement_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile(
            $code,
            'issue_34500_dom_childnodes_foreach_createelement_aot.php'
        ));

        return (string) ob_get_clean();
    }

    private function runAot(): string
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34500_dom_childnodes_foreach_createelement_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_cn_foreach_34500_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .'-d opcache.enable=0 '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));

            return implode("\n", $runOut)."\n";
        } finally {
            @unlink($bin);
        }
    }
}
