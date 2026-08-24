<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: nested appendChild(createElement('r')) then importNode (#34302 / re-#24571).
 *
 * ARG_SEND for the createElement string literal had a null Block operand while the
 * constant remained on the slot — rematerialize peer of bool/int/null (#27623).
 *
 * @see php-src ext/dom/document.c PHP_METHOD(DOMDocument, importNode)
 * @see php-src ext/dom/document.c PHP_METHOD(DOMDocument, createElement)
 *
 * @group llvm
 * @group aot
 */
final class DomImportCeNestedArgSend34302AotTest extends TestCase
{
    private const EXPECTED = "r\nr\n";

    public function testVmNestedCreateElementThenImportNode(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/dom_import_ce_nested.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'dom_import_ce_nested.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotNestedCreateElementThenImportNode(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/dom_import_ce_nested.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34302_ceimp_'.getmypid().'.bin';
        $cache = sys_get_temp_dir().'/phpc-hr-34302-'.getmypid();
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
