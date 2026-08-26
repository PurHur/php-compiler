<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: loadXML unprefixed root namespaceURI + getElementsByTagNameNS(ns,'*') (#35138).
 *
 * @see php-src ext/dom/node.c namespace_uri_read
 * @see php-src ext/dom/nodelist.c php_dom_get_elements_by_tag_name_ns_helper
 *
 * @group llvm
 * @group aot
 */
final class DomLoadXmlUnprefixedNsProps35138AotTest extends TestCase
{
    private const EXPECTED = "NULL\n'r'\n''\n2\na\nb\nNULL\n";

    public function testVmLoadXmlUnprefixedNsProps(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_35138_dom_loadxml_unprefixed_ns_props.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_35138_dom_loadxml_unprefixed_ns_props.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotLoadXmlUnprefixedNsProps(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_35138_dom_loadxml_unprefixed_ns_props.php';
        $bin = sys_get_temp_dir().'/phpc_issue_35138_ns_'.getmypid().'.bin';
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
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
