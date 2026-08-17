<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CaseCompareJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** strcasecmp()/strncasecmp() JIT routes through CaseCompareJitHelper PHP not libc LLVM (#15225, #23862). */
final class StrcasecmpRuntimeShrinkTest extends TestCase
{
    public function testStringCaseCompareUsesJitVmHelperLinkNotHandRolledNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringCaseCompare.php');
        $this->assertStringContainsString('CaseCompareJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
    }

    public function testStrcasecmpUsesPhpBridgeNotLibcOnly(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/strcasecmp.php');
        $this->assertStringContainsString('StringStrcasecmp::ensureLinked', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringCaseCompare.php');
        $this->assertStringContainsString('CaseCompareJitHelper', $bridge);
        $this->assertStringContainsString('strcasecmpArgv', $bridge);
    }

    public function testStrncasecmpUsesPhpBridgeNotLibcOnly(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/strncasecmp.php');
        $this->assertStringContainsString('StringStrncasecmp::ensureLinked', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringCaseCompare.php');
        $this->assertStringContainsString('strncasecmpArgv', $bridge);
    }

    public function testJitHelpersDelegateToVmString(): void
    {
        $this->assertSame(VmString::strcasecmp('A', 'a'), CaseCompareJitHelper::strcasecmpArgv('A', 'a'));
        $this->assertSame(VmString::strncasecmp('Ab', 'ab', 1), CaseCompareJitHelper::strncasecmpArgv('Ab', 'ab', 1));
        $this->assertSame(-1, VmString::strncasecmp('', 'a', 1));
        $this->assertSame(-1, CaseCompareJitHelper::strncasecmpArgv('', 'a', 1));
    }

    public function testStrncasecmpNullHaystackCoercesToEmptyString(): void
    {
        $this->assertSame(-1, VmString::strncasecmp('', 'a', 1));
        $this->assertSame(-1, CaseCompareJitHelper::strncasecmpArgv('', 'a', 1));
        $this->assertSame(1, VmString::strncasecmp('a', '', 1));
        $this->assertSame(-1, VmString::strncasecmp('ab', 'ABC', 3));
    }

    public function testSpineBundleIncludesCaseCompareJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('CaseCompareJitHelper.php', $spine);
        $this->assertStringContainsString('StringCaseCompare.php', $spine);
    }

    /** @return list<string> */
    private function nestedJitStrcasecmpConsumers(): array
    {
        return [
            'lib/JIT/InstanceOfHelper.php',
            'lib/JIT/ClassConstFetchHelperTrait.php',
            'lib/JIT/Builtin/ClassConstFetchRuntime.php',
            'lib/JIT/Builtin/ReflectionEnumJitHelper.php',
            'lib/JIT/Builtin/SessionModuleName.php',
            'ext/standard/JitIniGetAll.php',
            'ext/filter/JitFilterId.php',
        ];
    }

    public function testIntrospectionJitEmittersLinkCaseCompareBeforeStrcasecmp(): void
    {
        foreach ($this->nestedJitStrcasecmpConsumers() as $relativePath) {
            $source = (string) file_get_contents(__DIR__.'/../../'.$relativePath);
            $this->assertStringContainsString(
                'ensureStrcasecmpLinked',
                $source,
                "{$relativePath} must link CaseCompareJitHelper before strcasecmp lookup (#15256/#31787)"
            );
            $this->assertStringContainsString(
                'StringCaseCompare::ABI_STRCASECMP',
                $source,
                "{$relativePath} must look up __compiler_strcasecmp after #31787"
            );
            $this->assertStringNotContainsString(
                "addFunction('strcasecmp'",
                $source,
                "{$relativePath} must not declare empty strcasecmp LLVM stub (#15256)"
            );
            $this->assertStringNotContainsString(
                "lookupFunction('strcasecmp')",
                $source,
                "{$relativePath} must not look up libc strcasecmp after #31787"
            );
        }
    }

    public function testModulePhpLinksCaseCompareNotEmptyStrcasecmpStub(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringContainsString('StringCaseCompare::ensureStrcasecmpLinked', $source);
        $this->assertStringNotContainsString("addFunction('strcasecmp'", $source);
    }

    public function testObjectTypeRoutesStrncasecmpThroughCaseCompare(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/Object_.php');
        $this->assertStringContainsString('StringCaseCompare::ensureStrncasecmpLinked', $source);
        $this->assertStringContainsString('StringCaseCompare::ABI_STRNCASECMP', $source);
        $this->assertStringContainsString('#31682', $source);
        $this->assertStringNotContainsString("lookupFunction('strncasecmp')", $source);
        $this->assertStringNotContainsString("addFunction('strncasecmp'", $source);
    }
}
