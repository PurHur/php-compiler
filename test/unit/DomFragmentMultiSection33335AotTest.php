<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: many DocumentFragment mutations in one main must not SIGSEGV (#33335).
 *
 * Peer of #33312 / #33322 / #33327 — isolated ops stay green; combined main
 * previously crashed after c:main_before_php (heap / concat-phi pressure).
 *
 * @see php-src ext/dom/node.c dom_node_append_child / insert_before / replace_child
 *
 * @group llvm
 * @group aot
 */
final class DomFragmentMultiSection33335AotTest extends TestCase
{
    private const REPRO = '/repro/dom_fragment_multi_section_aot_segfault.php';

    private function expected(): string
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).self::REPRO);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'dom_fragment_multi_section_aot_segfault.php'));

        return (string) ob_get_clean();
    }

    public function testVmMultiSectionFragment(): void
    {
        $out = $this->expected();
        $this->assertStringContainsString('=== frag_replaceChild ===', $out);
        $this->assertStringContainsString('=== frag_replaceChild_last ===', $out);
        $this->assertStringContainsString('TypeError ok', $out);
    }

    public function testAotMultiSectionFragmentMatchesVmOverRepeats(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test'.self::REPRO;
        $bin = sys_get_temp_dir().'/phpc_issue_33335_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='
            .escapeshellarg(sys_get_temp_dir().'/phpc-hr-33335-'.getmypid())
            .' '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $expected = $this->expected();
        try {
            for ($i = 0; $i < 20; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($expected, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
