<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: chained firstChild->nodeName after setAttribute/getAttribute/replaceChild.
 *
 * @see php-src ext/dom/node.c dom_node_replace_child
 *
 * @group llvm
 * @group aot
 */
final class DomReplaceChildGetAttrFirstChildNodeNameAotTest extends TestCase
{
    private const EXPECTED = "getAttribute.id=main\nchained=child3\nassigned=child3\nDONE\n";

    public function testVmReplaceChildGetAttrFirstChildNodeName(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/dom_replacechild_getattr_firstchild_nodename_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'dom_replacechild_getattr_firstchild_nodename_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotReplaceChildGetAttrFirstChildNodeName(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/dom_replacechild_getattr_firstchild_nodename_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_rc_ga_fc_'.getmypid().'.bin';
        $cache = sys_get_temp_dir().'/phpc-hr-rc-ga-fc-'.getmypid();
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='
            .escapeshellarg($cache)
            .' '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $ok = 0;
            $last = '';
            for ($i = 0; $i < 10; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $last = implode("\n", $runOut);
                if (0 === $runRc && self::EXPECTED === $last."\n") {
                    ++$ok;
                }
            }
            $this->assertSame(
                10,
                $ok,
                "expected 10/10 AOT matches; last=[{$last}]"
            );
        } finally {
            @unlink($bin);
        }
    }
}
