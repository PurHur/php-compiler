<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: child may widen inherited property visibility protected→public (#25661).
 *
 * @see php-src Zend/zend_inheritance.c do_inherit_property
 * @see lib/Compiler/PropertyVisibilityInheritCheck.php
 *
 * @group llvm
 * @group aot
 */
final class InheritPropVisibilityWiden25661AotTest extends TestCase
{
    private const EXPECT = "pub,pub\n";

    public function testVmInheritedPropertyVisibilityWidenMatchesZend(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_inherit_prop_visibility_widen.php');
        $this->assertNotFalse($code);
        ob_start();
        (new \PHPCompiler\Runtime())->run(
            (new \PHPCompiler\Runtime())->parseAndCompile($code, 'issue_inherit_prop_visibility_widen.php')
        );
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotInheritedPropertyVisibilityWidenMatchesVm(): void
    {
        $root = dirname(__DIR__, 2);
        if (!\PHPCompiler\LlvmToolchain::hasLibrary($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = $root.'/test/repro/issue_inherit_prop_visibility_widen.php';
        $bin = sys_get_temp_dir().'/phpc_inh_vis_widen_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECT, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
