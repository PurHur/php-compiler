<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: inherited ctor-promoted defaults defer when subclass defines __construct (#6652 leftover).
 *
 * @group aot
 *
 * @see php-src Zend/zend_objects.c — parent ctor body not run without parent::__construct
 */
final class InheritedPromotedDeferChildCtorAotTest extends TestCase
{
    private const EXPECT = "Error: Typed property ParentPromotedDefer::\$dt must not be accessed before initialization\nchild\n";

    public function testVmInheritedPromotedDeferChildCtorMatchesZend(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_inherited_promoted_defer_child_ctor.php');
        $this->assertNotFalse($code);
        ob_start();
        (new Runtime())->run((new Runtime())->parseAndCompile($code, 'issue_inherited_promoted_defer_child_ctor.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotInheritedPromotedDeferChildCtorMatchesVm(): void
    {
        $root = dirname(__DIR__, 2);
        if (!\PHPCompiler\LlvmToolchain::hasLibrary($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = $root.'/test/repro/issue_inherited_promoted_defer_child_ctor.php';
        $bin = sys_get_temp_dir().'/phpc_inh_promo_defer_'.getmypid().'.bin';
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
