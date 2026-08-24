<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Unused appendChild(createElement) before importNode must match Zend (#34405).
 *
 * @see php-src ext/dom/document.c PHP_METHOD(DOMDocument, importNode)
 *
 * @group llvm
 * @group aot
 */
final class DomUnusedAppendChildCreateElementImportNodeAotTest extends TestCase
{
    public function testVmAndAotMatchZendNoPhantomRoot(): void
    {
        $src = __DIR__.'/../repro/issue_dom_unused_appendchild_createelement_importnode_aot.php';
        $zend = $this->runPhp($src);
        $vm = $this->runVm($src);
        $this->assertSame($zend, $vm, 'VM must match Zend');
        $this->assertStringContainsString("cn=1\n", $vm);
        $this->assertStringNotContainsString('<root/>', $vm);

        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot, 'AOT must match Zend');
    }

    public function testOpcodeStreamDoesNotDoubleEmitCreateElementBeforeImportNode(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_dom_unused_appendchild_createelement_importnode_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/print.php').' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $text = implode("\n", $out);
            $opcodes = $text;
            if (false !== ($p = strpos($text, 'OpCodes:'))) {
                $opcodes = substr($text, $p);
            }
            $createInits = preg_match_all(
                "/TYPE_METHODCALL_INIT\\([^\\n]*LITERAL\\('createElement'\\)/",
                $opcodes
            );
            $this->assertSame(1, $createInits, $opcodes);
            $importInits = preg_match_all(
                "/TYPE_METHODCALL_INIT\\([^\\n]*LITERAL\\('importNode'\\)/",
                $opcodes
            );
            $this->assertSame(1, $importInits, $opcodes);
        } finally {
            chdir($cwd);
        }
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runVm(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'env PHP_COMPILER_PROFILE=8.3 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
        }
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/dom_unused_ac_'.getmypid().'_'.md5($src);
        $cmd = 'env PHP_COMPILER_PROFILE=8.3 PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
