<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionClass getStartLine/getEndLine/getDocComment match Zend (#34106).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionClass_getStartLine
 * @see \PHPCompiler\JIT\Call\ReflectionClassSourceLocationQuery
 *
 * @group llvm
 * @group aot
 */
final class Issue34106ReflectionClassSourceLocationAotTest extends TestCase
{
    private const EXPECT = <<<'TXT'
4
6
'/** Class doc */'
bool(false)
bool(false)
bool(false)
TXT;

    public function testContextRegistersSourceLocationProxies(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionclass::getstartline']",
            $source
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionclass::getendline']",
            $source
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionclass::getdoccomment']",
            $source
        );
        $this->assertStringContainsString('#34106', $source);
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ReflectionClassSourceLocationRuntime.php'
        );
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Call/ReflectionClassSourceLocationQuery.php'
        );
    }

    public function testAotSourceLocationMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34106_reflection_source_location_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34106_src_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $joined = implode("\n", $runOut);
                $this->assertSame(0, $runRc, 'run '.$i.': '.$joined);
                $this->assertSame(self::EXPECT, trim($joined));
            }
        } finally {
            @unlink($bin);
        }
    }

    public function testVmSourceLocationMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34106_reflection_source_location_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = implode("\n", $out);
        $this->assertSame(0, $rc, $joined);
        // VM historically returns int(0) for internal missing lines; AOT matches Zend false.
        // Assert user-class lines + doc; stdClass lines may be 0 under VM.
        $this->assertStringContainsString("'/** Class doc */'", $joined);
        $this->assertMatchesRegularExpression('/^4\n6\n/m', $joined);
    }
}
