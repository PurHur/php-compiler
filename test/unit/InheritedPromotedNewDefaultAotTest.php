<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * Inherited promoted ctor `new` defaults must apply on subclass `new` (#6652 leftover).
 *
 * @see php-src Zend/zend_objects.c — copy default from parent prop_info
 */
final class InheritedPromotedNewDefaultAotTest extends TestCase
{
    private const EXPECT = "direct:2021-06-15\nchild:2021-06-15\nmarker:builtin\n";

    public function testVmInheritedPromotedNewDefaultMatchesZend(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_inherited_promoted_new_default.php');
        $this->assertNotFalse($code);
        ob_start();
        (new Runtime())->run((new Runtime())->parseAndCompile($code, 'issue_inherited_promoted_new_default.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotInheritedPromotedNewDefaultMatchesVm(): void
    {
        $root = dirname(__DIR__, 2);
        if (!\PHPCompiler\LlvmToolchain::hasLibrary($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = $root.'/test/repro/issue_inherited_promoted_new_default.php';
        $bin = sys_get_temp_dir().'/phpc_inh_promo_new_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin), $runOut, $runRc);
        $this->assertSame(0, $runRc);
        $this->assertSame(self::EXPECT, implode("\n", $runOut).([] === $runOut ? '' : "\n"));
        @unlink($bin);
    }
}
