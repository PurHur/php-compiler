<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: appendChild/insertBefore/replaceChild foreign Element → Wrong Document (#33937).
 *
 * @see php-src ext/dom/node.c WRONG_DOCUMENT_ERR
 *
 * @group llvm
 * @group aot
 */
final class DomMutationForeignElementWrongDocument33937AotTest extends TestCase
{
    private const REPRO = __DIR__.'/../repro/dom_mutation_foreign_element_wrong_document.php';

    public function testVmMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg(self::REPRO).' 2>&1';
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $text = implode("\n", $out)."\n";
        $this->assertStringContainsString('appendChild code=4 msg=Wrong Document Error', $text);
        $this->assertStringContainsString('insertBefore code=4 msg=Wrong Document Error', $text);
        $this->assertStringContainsString('replaceChild code=4 msg=Wrong Document Error', $text);
        $this->assertStringContainsString('createElement code=4 msg=Wrong Document Error', $text);
        $this->assertStringContainsString('same-doc len=2', $text);
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/phpc_foreign_el_wrong_doc_33937_'.getmypid().'.bin';

        $zendOut = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::REPRO).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";

        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg(self::REPRO).' 2>&1';
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
