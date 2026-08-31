<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: inherited promoted ctor `new` defaults apply when child calls parent::__construct() (#6652).
 *
 * @group aot
 *
 * @see php-src Zend/zend_objects.c — deferred allocate init runs at parent ctor default eval
 */
final class InheritedPromotedParentConstructAotTest extends TestCase
{
    private const EXPECT = "2021-06-15\n2022-03-04\nError: Typed property ParentDt::\$dt must not be accessed before initialization\n";

    public function testVmInheritedPromotedParentConstructMatchesZend(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_inherited_promoted_parent_construct.php');
        $this->assertNotFalse($code);
        ob_start();
        (new Runtime())->run((new Runtime())->parseAndCompile($code, 'issue_inherited_promoted_parent_construct.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotInheritedPromotedParentConstructMatchesVm(): void
    {
        $root = dirname(__DIR__, 2);
        if (!\PHPCompiler\LlvmToolchain::hasLibrary($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = $root.'/test/repro/issue_inherited_promoted_parent_construct.php';
        $bin = sys_get_temp_dir().'/phpc_inh_promo_parent_'.getmypid().'.bin';
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
