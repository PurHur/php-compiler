<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: inline DOM method()?->prop must match Zend (#34024).
 *
 * @see php-src ext/dom/node.c dom_node_clone_node / dom_node_append_child
 * @see php-src ext/dom/document.c dom_document_create_element / dom_document_import_node
 *
 * @group llvm
 * @group aot
 */
final class Issue34024DomNullsafeCallAotTest extends TestCase
{
    public function testAssignCallResultForcesValueForCfgObject(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('callResultCfgWantsObject', $source);
        $this->assertStringContainsString('#34024', $source);
        $this->assertStringContainsString('call_value_box_object_post_assign', $source);
    }

    public function testCloneNodeBoxesObjectResult(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/ext/dom/JitDomCloneNode.php');
        $this->assertStringContainsString('boxObjectResult', $source);
        $this->assertStringContainsString('#34024', $source);
    }

    public function testVmInlineNullsafeMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34024_dom_nullsafe_call_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = implode("\n", $out);
        $this->assertSame(0, $rc, $joined);
        $this->assertSame("r\nx\nx\nr\nr\ne", trim($joined));
    }

    public function testAotInlineNullsafeMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34024_dom_nullsafe_call_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34024_dom_'.getmypid().'.bin';
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
            $this->assertSame("r\nx\nx\nr\nr\ne", trim($joined));
        } finally {
            @unlink($bin);
        }
    }
}
