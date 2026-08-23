<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: TypeError from a closure invoked via foreach must be catchable (peer #33971).
 *
 * Without ClosureWithCaptures on the iter value local, RuntimeIndirectClosureCall skips
 * pending-throw catch wiring and aborts under AOT while VM/Zend catch normally.
 *
 * @see php-src ext/dom/php_dom.stub.php DOMDocument::saveXML(?DOMNode $node)
 *
 * @group llvm
 * @group aot
 */
final class DomForeachClosureTypeErrorAotTest extends TestCase
{
    private const SAVEXML_INT = "saveXML_int=TypeError:DOMDocument::saveXML(): Argument #1 (\$node) must be of type ?DOMNode, int given\n";

    public function testVmForeachClosureOuterCatch(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_dom_savexml_savehtml_node_typeerror.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_dom_savexml_savehtml_node_typeerror.php'));
        $out = (string) ob_get_clean();
        $this->assertStringContainsString(self::SAVEXML_INT, $out);
        $this->assertStringContainsString('null_options=ok', $out);
    }

    public function testAotForeachClosureOuterCatch(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_dom_savexml_savehtml_node_typeerror.php';
        $bin = sys_get_temp_dir().'/phpc_dom_foreach_closure_'.getmypid().'.bin';
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
            $joined = implode("\n", $runOut)."\n";
            $this->assertStringContainsString(self::SAVEXML_INT, $joined);
            $this->assertStringContainsString('saveHTML_int=TypeError:', $joined);
            $this->assertStringContainsString('saveXML_string=TypeError:', $joined);
            // saveXML(null, LIBXML_NOEMPTYTAG) formatting is a separate gap (#34225 not covered).
        } finally {
            @unlink($bin);
        }
    }
}
