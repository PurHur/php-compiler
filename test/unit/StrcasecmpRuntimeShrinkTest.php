<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CaseCompareJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** strcasecmp()/strncasecmp() JIT routes through CaseCompareJitHelper PHP not libc LLVM (#15225). */
final class StrcasecmpRuntimeShrinkTest extends TestCase
{
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
    }

    public function testSpineBundleIncludesCaseCompareJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('CaseCompareJitHelper.php', $spine);
        $this->assertStringContainsString('StringCaseCompare.php', $spine);
    }
}
