<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — Nyholm RequestTrait assigns MessageTrait::$headers; AOT must not invent a
 * second trait property declaration (zend_do_traits_property_binding).
 *
 * php-src: Zend/zend_inheritance.c zend_do_traits_property_binding
 */
final class Issue36382NyholmTraitHeadersAotTest extends TestCase
{
    public function testNyholmShapedTraitHeadersAssignMatchesZend(): void
    {
        if (!getenv('PHP_COMPILER_LLVM_PATH') && !is_dir(__DIR__.'/../../.llvm')) {
            $this->markTestSkipped('LLVM 9 not available');
        }
        $src = realpath(__DIR__.'/../repro/issue_36382_nyholm_trait_headers.php');
        $this->assertNotFalse($src);
        $bin = sys_get_temp_dir().'/issue_36382_nyholm_trait_headers_'.getmypid();
        @unlink($bin);
        $cmd = sprintf(
            'php -d memory_limit=512M %s -o %s %s 2>&1',
            escapeshellarg(realpath(__DIR__.'/../../bin/compile.php')),
            escapeshellarg($bin),
            escapeshellarg($src)
        );
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runCode);
        @unlink($bin);
        $this->assertSame(0, $runCode, implode("\n", $runOut));
        $this->assertSame("example.com\n", implode("\n", $runOut)."\n");
    }
}
