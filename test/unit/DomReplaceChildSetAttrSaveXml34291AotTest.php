<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: createElement+setAttribute+replaceChild must keep attrs in saveXML (#34291).
 *
 * @see php-src ext/dom/node.c dom_node_replace_child
 * @see php-src ext/dom/element.c setAttribute
 *
 * @group llvm
 * @group aot
 */
final class DomReplaceChildSetAttrSaveXml34291AotTest extends TestCase
{
    private const EXPECTED = "<r><a/><x id=\"x\" k=\"v\"/><c/></r>\nx|v\n";

    public function testVmReplaceChildSetAttrSaveXml(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34291_dom_replacechild_setattr_savexml_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34291_dom_replacechild_setattr_savexml_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotReplaceChildSetAttrSaveXml(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34291_dom_replacechild_setattr_savexml_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_rc_sa_'.getmypid().'.bin';
        $cache = sys_get_temp_dir().'/phpc-hr-rc-sa-'.getmypid();
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
