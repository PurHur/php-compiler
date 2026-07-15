<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Bin2hexJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\StringBin2hex;
use PHPCompiler\JIT\Context;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** bin2hex() JIT routes through Bin2hexJitHelper PHP, not inline defer LLVM (#14603, #19126). */
final class Bin2hexRuntimeShrinkTest extends TestCase
{
    public function testStringBin2hexUsesJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringBin2hex.php');
        $this->assertStringContainsString('Bin2hexJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('implementInlineLlvm', $source);
        $this->assertStringNotContainsString('bin2hex_inline_entry', $source);
        $this->assertStringNotContainsString('StringBin2hexLlvm', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringBin2hexLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitBin2hex.php');
        $loc = substr_count($source, "\n") + 1;
        $this->assertLessThan(90, $loc, 'StringBin2hex.php LOC after defer LLVM removal');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/bin2hex.php');
        $this->assertStringContainsString('StringBin2hex::ensureLinked', $builtin);
        $this->assertStringContainsString('__compiler_bin2hex', $builtin);
        $this->assertStringContainsString('stringBuiltinArgForFrame', $builtin);
        $this->assertStringContainsString('lowerStrictOrCoercible', $builtin);
        $this->assertStringNotContainsString('JitBin2hex', $builtin);
    }

    public function testBin2hexJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Bin2hexJitHelper.php');
        $this->assertStringContainsString('VmString::bin2hex', $source);

        $this->assertSame('616263', Bin2hexJitHelper::bin2hexArgv('abc'));
        $this->assertSame('616263', VmString::bin2hex('abc'));
    }

    public function testSpineBundleOmitsDeletedBin2hexLlvm(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitBin2hex.php', $spine);
        $this->assertStringNotContainsString('StringBin2hexLlvm.php', $spine);
        $this->assertStringContainsString('Bin2hexJitHelper.php', $spine);
        $this->assertStringContainsString('StringBin2hex.php', $spine);
    }

    public function testEnsureLinkedDefinesBin2hexForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringBin2hex::ensureLinked($ctx);

        $fn = $ctx->lookupFunction('__compiler_bin2hex');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }
}
