<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrIncdecJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** str_increment()/str_decrement() JIT routes through StrIncdecJitHelper PHP not inline LLVM (#14850). */
final class StrIncdecRuntimeShrinkTest extends TestCase
{
    public function testStringStrIncdecUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrIncdec.php');
        $this->assertStringContainsString('StrIncdecJitHelper', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitStrIncdec.php');

        $increment = (string) file_get_contents(__DIR__.'/../../ext/standard/str_increment.php');
        $this->assertStringContainsString('StringStrIncdec::invokeIncrement', $increment);
        $this->assertStringNotContainsString('JitStrIncdec', $increment);

        $decrement = (string) file_get_contents(__DIR__.'/../../ext/standard/str_decrement.php');
        $this->assertStringContainsString('StringStrIncdec::invokeDecrement', $decrement);
        $this->assertStringNotContainsString('JitStrIncdec', $decrement);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrIncdec.php');
        $this->assertStringContainsString('phpc_str_increment', $bridge);
        $this->assertStringContainsString('phpc_str_decrement', $bridge);
        $this->assertStringContainsString('phpc_increment_string', $bridge);
        $this->assertStringContainsString('phpc_string_incdec_kind', $bridge);
    }

    public function testStrIncdecJitHelperInlinesWithoutVmStringCall(): void
    {
        // NestedJIT must not call VmString — unbound stub segfaults thin AOT (#27345 / #23204).
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StrIncdecJitHelper.php');
        $this->assertDoesNotMatchRegularExpression('/return\s+VmString::str(Increment|Decrement)\s*\(/', $source);
        $this->assertStringNotContainsString('VmString::strIncrement($', $source);
        $this->assertStringNotContainsString('VmString::strDecrement($', $source);
        // VM builtins still SSOT via VmString.
        $this->assertStringContainsString('VmString::strIncrement', (string) file_get_contents(
            __DIR__.'/../../ext/standard/str_increment.php'
        ));
        $this->assertStringContainsString('VmString::strDecrement', (string) file_get_contents(
            __DIR__.'/../../ext/standard/str_decrement.php'
        ));

        $this->assertSame('b', StrIncdecJitHelper::incrementArgv('a'));
        $this->assertSame('b', VmString::strIncrement('a'));
        $this->assertSame('a', StrIncdecJitHelper::decrementArgv('b'));
        $this->assertSame('a', VmString::strDecrement('b'));
        $this->assertSame('10', StrIncdecJitHelper::incrementArgv('9'));
        $this->assertSame('aa', StrIncdecJitHelper::incrementArgv('z'));
        $this->assertSame('Ba', StrIncdecJitHelper::incrementArgv('Az'));
        $this->assertSame('9', StrIncdecJitHelper::decrementArgv('10'));
        $this->assertSame('z', StrIncdecJitHelper::decrementArgv('aa'));
        $this->assertSame('b', StrIncdecJitHelper::operatorIncrement('a'));
        $this->assertSame('1', StrIncdecJitHelper::operatorIncrement(''));
        $this->assertSame('aa', StrIncdecJitHelper::operatorIncrement('z'));
        $this->assertSame('a-a', StrIncdecJitHelper::operatorIncrement('a-z'));
        $this->assertSame('b0', VmString::incrementStringOperator('a9'));
        $this->assertSame(0, StrIncdecJitHelper::numericIncDecKind('a'));
        $this->assertSame(1, StrIncdecJitHelper::numericIncDecKind('9'));
        $this->assertSame(2, StrIncdecJitHelper::numericIncDecKind('1.5'));
    }

    public function testSpineBundleIncludesStrIncdecJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitStrIncdec.php', $spine);
        $this->assertStringContainsString('StrIncdecJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrIncdec.php', $spine);
    }

    /** #27436 — user-script AOT must NestedJIT StrIncdec (not IR-stale helper unit.o). */
    public function testHelperRuntimeInlineOnlyIncludesStrIncdec(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/AOT/HelperRuntimeCache.php');
        $this->assertStringContainsString('#27436', $source);
        $this->assertStringContainsString("strincdecjithelper::incrementargv' => true", $source);
        $this->assertStringContainsString("strincdecjithelper::decrementargv' => true", $source);
        $this->assertStringContainsString("strincdecjithelper::operatorincrement' => true", $source);
        $this->assertStringContainsString("strincdecjithelper::numericincdeckind' => true", $source);
    }
}
