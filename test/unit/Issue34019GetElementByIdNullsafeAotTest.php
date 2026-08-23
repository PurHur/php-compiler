<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: inline getElementById(...)?->prop must short-circuit on miss (#34019).
 *
 * @see php-src ext/dom/php_dom.c dom_document_get_element_by_id
 * @see \PHPCompiler\JIT\Call\DomDocumentGetElementById
 *
 * @group llvm
 * @group aot
 */
final class Issue34019GetElementByIdNullsafeAotTest extends TestCase
{
    public function testAssignCallResultForcesValueStorage(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('DomDocumentGetElementById', $source);
        $this->assertStringContainsString('#34019', $source);
        $this->assertMatchesRegularExpression(
            '/DomDocumentGetElementById.*?TYPE_VALUE.*?classUserType\s*=\s*\'DOMElement\'/s',
            $source
        );
    }

    public function testVmInlineAndAssignedNullsafe(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34019_getelementbyid_nullsafe_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = implode("\n", $out);
        $this->assertSame(0, $rc, $joined);
        $this->assertSame("null\nnull", trim($joined));
    }

    public function testAotInlineAndAssignedNullsafe(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34019_getelementbyid_nullsafe_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34019_gei_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $joined = implode("\n", $runOut);
            $this->assertSame(0, $runRc, $joined);
            $this->assertSame("null\nnull", trim($joined));
        } finally {
            @unlink($bin);
        }
    }
}
