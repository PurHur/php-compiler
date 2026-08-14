<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Nl2brJitHelper;
use PHPCompiler\ext\standard\VmNl2br;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** nl2br() JIT routes through Nl2brJitHelper + VmNl2br (#14714, #21630, #30813). */
final class Nl2brRuntimeShrinkTest extends TestCase
{
    public function testStringNl2brUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringNl2br.php');
        $this->assertStringContainsString('Nl2brJitHelper', $source);
        $this->assertStringContainsString('VmNl2br.php', $source);
        $this->assertStringContainsString('ensureCompiledBundle', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('ensureJitHelperCompiled', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitNl2br.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/nl2br.php');
        $this->assertStringContainsString('StringNl2br::ensureLinked', $builtin);
        $this->assertStringContainsString('__string__nl2br', $builtin);
        $this->assertStringNotContainsString('JitNl2br', $builtin);

        $cache = (string) file_get_contents(__DIR__.'/../../lib/AOT/HelperRuntimeCache.php');
        $this->assertStringContainsString('nl2brjithelper::nl2brargv', $cache);
    }

    public function testNl2brJitHelperDelegatesToVmNl2brAndMatchesVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Nl2brJitHelper.php');
        $this->assertDoesNotMatchRegularExpression('/\bVmString::/', $source);
        $this->assertStringContainsString('VmNl2br::nl2br', $source);

        $vm = (string) file_get_contents(__DIR__.'/../../ext/standard/VmNl2br.php');
        $this->assertStringNotContainsString('$string[$', $vm);
        $this->assertStringContainsString('\\strlen(', $vm);
        $this->assertStringContainsString('\\substr(', $vm);
        $this->assertStringContainsString('buildFrom', $vm);

        $this->assertSame("a<br />\nb", Nl2brJitHelper::nl2brArgv("a\nb", 1));
        $this->assertSame("a<br />\nb", VmString::nl2br("a\nb", true));
        $this->assertSame("a<br />\nb", VmNl2br::nl2br("a\nb", 1));
        $this->assertSame("a<br>\nb", Nl2brJitHelper::nl2brArgv("a\nb", 0));
        $this->assertSame("a<br>\nb", VmString::nl2br("a\nb", false));
        $this->assertSame("a<br>\r\nb", Nl2brJitHelper::nl2brArgv("a\r\nb", 0));
        $this->assertSame("a<br />\r\nb", Nl2brJitHelper::nl2brArgv("a\r\nb", 1));
        $this->assertSame('plain', Nl2brJitHelper::nl2brArgv('plain', 1));
    }

    public function testSpineBundleIncludesVmNl2br(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitNl2br.php', $spine);
        $this->assertStringContainsString('VmNl2br.php', $spine);
        $this->assertStringContainsString('Nl2brJitHelper.php', $spine);
        $this->assertStringContainsString('StringNl2br.php', $spine);
    }
}
