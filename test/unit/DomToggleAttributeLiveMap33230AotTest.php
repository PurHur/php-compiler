<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: toggleAttribute keeps held NamedNodeMap live (#33230 / peer #33143).
 *
 * @see php-src ext/dom/element.c dom_element_toggle_attribute
 *
 * @group llvm
 * @group aot
 */
final class DomToggleAttributeLiveMap33230AotTest extends TestCase
{
    private const EXPECTED =
        "before=2 a\n".
        "toggle_ret=0\n".
        "after=1 b\n".
        "has_a=0 get_a=''\n".
        "has_b=1\n".
        "force_true_has=1 map=2\n".
        "force_false_has=0 map=1\n";

    public function testVmToggleAttributeLiveMap(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $runtime = new Runtime();
            $code = file_get_contents(
                dirname(__DIR__).'/repro/issue_33230_dom_toggleattribute_live_map.php'
            );
            $this->assertNotFalse($code);
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'issue_33230_dom_toggleattribute_live_map.php'));
            $out = (string) ob_get_clean();
            $this->assertSame(self::EXPECTED, $out);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAotToggleAttributeLiveMap(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33230_dom_toggleattribute_live_map.php';
        $bin = sys_get_temp_dir().'/phpc_dom_toggle_33230_'.getmypid().'.bin';
        try {
            $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_PROFILE=8.3 '
                .escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $this->assertFileExists($bin);
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            if (is_file($bin)) {
                @unlink($bin);
            }
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
