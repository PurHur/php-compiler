<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: replaceChild($n,$n) no-op; insertBefore($n,$n) Error (#34709 / re-#22678/#22686).
 *
 * php-src: ext/dom/node.c dom_node_replace_child / dom_node_insert_before
 *
 * @group llvm
 * @group aot
 */
final class DomSameNodeMutation34709AotTest extends TestCase
{
    private const COMBINED = __DIR__.'/../repro/issue_34709_dom_same_node_mutation_aot.php';

    private const INSERT_ONLY = __DIR__.'/../repro/issue_34709_dom_insertbefore_same_ref_aot.php';

    public function testSameNodeMutationVm(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(self::COMBINED);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34709_dom_same_node_mutation_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(
            "xml=<r><a/><b/></r>\n"
            ."len=2\n"
            ."ret_same=yes\n"
            ."a_parent_is_r=yes\n"
            ."a_next=b\n"
            ."b_prev=a\n"
            ."insert_self=Error:Cannot add newnode as the previous sibling of refnode\n",
            $out
        );
    }

    public function testSameNodeMutationAot(): void
    {
        $this->assertAotMatchesZend(self::COMBINED, 'phpc_dom_same_mut_34709_');
    }

    public function testInsertBeforeSameRefAot(): void
    {
        $this->assertAotMatchesZend(self::INSERT_ONLY, 'phpc_dom_ib_same_34709_');
    }

    private function assertAotMatchesZend(string $src, string $binPrefix): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/'.$binPrefix.getmypid().'.bin';

        $zendOut = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";

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
            $this->assertSame($zend, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
