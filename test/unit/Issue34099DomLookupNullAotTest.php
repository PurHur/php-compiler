<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: lookupPrefix(null) / isDefaultNamespace(null) match Zend (#34099).
 *
 * @see php-src ext/dom/node.c PHP_METHOD(DOMNode, lookupPrefix)
 * @see php-src ext/dom/node.c PHP_METHOD(DOMNode, isDefaultNamespace)
 *
 * @group llvm
 * @group aot
 */
final class Issue34099DomLookupNullAotTest extends TestCase
{
    private const EXPECTED_COERCE = "NULL|NULL|'foo'|false|false|true\n";

    private const EXPECTED_STRICT = "lookupPrefix=DOMNode::lookupPrefix(): Argument #1 (\$namespace) must be of type string, null given\n"
        ."isDefaultNamespace=DOMNode::isDefaultNamespace(): Argument #1 (\$namespace) must be of type string, null given\n";

    public function testVmLookupNullCoerce(): void
    {
        $this->assertVmMatches(
            'issue_34099_dom_lookup_null_aot.php',
            self::EXPECTED_COERCE
        );
    }

    public function testAotLookupNullCoerce(): void
    {
        $this->assertAotMatches(
            'issue_34099_dom_lookup_null_aot.php',
            self::EXPECTED_COERCE
        );
    }

    public function testVmLookupNullStrict(): void
    {
        $this->assertVmMatches(
            'issue_34099_dom_lookup_null_strict_aot.php',
            self::EXPECTED_STRICT
        );
    }

    public function testAotLookupNullStrict(): void
    {
        $this->assertAotMatches(
            'issue_34099_dom_lookup_null_strict_aot.php',
            self::EXPECTED_STRICT
        );
    }

    private function assertVmMatches(string $repro, string $expected): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/'.$repro);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, $repro));
        $out = (string) ob_get_clean();
        $this->assertSame($expected, $out);
    }

    private function assertAotMatches(string $repro, string $expected): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/'.$repro;
        $bin = sys_get_temp_dir().'/phpc_dom_lookup_null_'.getmypid().'_'.md5($repro).'.bin';
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
            $this->assertSame($expected, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
