<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT/VM: namespaced attr must not satisfy hasAttribute/hasAttributeNS(null, local) (#34330).
 *
 * php-src: ext/dom/element.c — xmlHasProp / xmlHasNsProp
 *
 * @group llvm
 * @group aot
 */
final class DomHasAttrNsNull34330AotTest extends TestCase
{
    /** @return array<string, string> */
    private function parseFields(string $out): array
    {
        $fields = [];
        if (preg_match_all('/(hasAttribute(?:NS)?\([^)]*\)|getAttribute(?:NS)?\([^)]*\)|both:[^=]+)=(\[[^\]]*\]|[01])/', $out, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $fields[$row[1]] = $row[2];
            }
        }

        return $fields;
    }

    public function testVmNamespacedAttrDoesNotSatisfyNullNsLookup(): void
    {
        $src = dirname(__DIR__).'/repro/issue_dom_hasattrns_null_ns_aot.php';
        $zend = $this->parseFields($this->runPhp($src));
        $runtime = new Runtime();
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_dom_hasattrns_null_ns_aot.php'));
        $vm = $this->parseFields((string) ob_get_clean());
        $this->assertSame($zend, $vm);
    }

    public function testAotNamespacedAttrDoesNotSatisfyNullNsLookup(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = dirname(__DIR__).'/repro/issue_dom_hasattrns_null_ns_aot.php';
        $zend = $this->parseFields($this->runPhp($src));
        $aot = $this->parseFields($this->runAot($src));
        $this->assertSame($zend, $aot);
        $this->assertSame('0', $aot['hasAttribute(a)'] ?? null);
        $this->assertSame('0', $aot['hasAttributeNS(null,a)'] ?? null);
        $this->assertSame('[]', $aot['getAttribute(a)'] ?? null);
        $this->assertSame('1', $aot['hasAttribute(p:a)'] ?? null);
        $this->assertSame('1', $aot['both:hasAttributeNS(null,a)'] ?? null);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/phpc_dom_hasattrns_34330_'.getmypid().'.bin';
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

            return implode("\n", $runOut);
        } finally {
            @unlink($bin);
        }
    }
}
