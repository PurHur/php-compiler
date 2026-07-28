<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrContainsJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** str_contains/str_starts_with/str_ends_with JIT routes (#14768, #24161). */
final class StrContainsRuntimeShrinkTest extends TestCase
{
    public function testStringStrContainsUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrContains.php');
        $this->assertStringContainsString('StrContainsJitHelper', $source);
        $this->assertStringContainsString('VmStringCompare::prefixIdentical', $source);
        $this->assertStringContainsString('VmStringCompare::suffixIdentical', $source);
        $this->assertStringContainsString('invokeStartsWith', $source);
        $this->assertStringContainsString('invokeEndsWith', $source);

        $search = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStringSearch.php');
        $this->assertStringNotContainsString('function contains(', $search);
        $this->assertStringNotContainsString('function startsWith(', $search);
        $this->assertStringNotContainsString('function endsWith(', $search);

        $contains = (string) file_get_contents(__DIR__.'/../../ext/standard/str_contains.php');
        $this->assertStringContainsString('StringStrContains::invokeContains', $contains);

        $starts = (string) file_get_contents(__DIR__.'/../../ext/standard/str_starts_with.php');
        $this->assertStringContainsString('StringStrContains::invokeStartsWith', $starts);

        $ends = (string) file_get_contents(__DIR__.'/../../ext/standard/str_ends_with.php');
        $this->assertStringContainsString('StringStrContains::invokeEndsWith', $ends);
    }

    public function testStrContainsJitHelperDelegatesToVmString(): void
    {
        $this->assertTrue(StrContainsJitHelper::containsArgv('hello', 'ell'));
        $this->assertFalse(StrContainsJitHelper::containsArgv('hello', 'z'));
        $this->assertTrue(StrContainsJitHelper::containsArgv('hello', ''));
        $this->assertTrue(StrContainsJitHelper::startsWithArgv('hello', 'he'));
        $this->assertFalse(StrContainsJitHelper::startsWithArgv('hello', 'lo'));
        $this->assertTrue(StrContainsJitHelper::startsWithArgv('Hello World', 'Hello'));
        $this->assertSame(VmString::endsWith('hello', 'lo'), StrContainsJitHelper::endsWithArgv('hello', 'lo'));
    }

    public function testSpineBundleIncludesStrContainsJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('StrContainsJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrContains.php', $spine);
    }
}
