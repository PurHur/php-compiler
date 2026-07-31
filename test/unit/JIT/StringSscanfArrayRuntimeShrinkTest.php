<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/** sscanf() array-return JIT NestedJIT via JitVmHelperLink::ensureCompiled (#9134, #25653). */
final class StringSscanfArrayRuntimeShrinkTest extends TestCase
{
    public function testSscanfJitHelperDelegatesToVmSscanf(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../ext/standard/SscanfJitHelper.php');
        $this->assertStringContainsString('VmSscanf::parseToArray', $source);
    }

    public function testStringSscanfArrayRoutesThroughSscanfJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringSscanfArray.php');
        $this->assertStringContainsString('SscanfJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('emitCompilerSscanfArray', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertLessThan(180, \substr_count($source, "\n") + 1);

        $router = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/Sscanf.php');
        $this->assertStringContainsString('StringSscanfArray::implement', $router);
        $this->assertStringContainsString('StringSscanfByRef::implement', $router);
        $this->assertStringContainsString('StringVfscanf::implement', $router);
        $this->assertStringNotContainsString('SscanfJit::', $router);
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/SscanfJit.php');
    }

    public function testSscanfJitHelperSemanticsMatchVmSscanf(): void
    {
        $ht = \PHPCompiler\ext\standard\SscanfJitHelper::parseToArray('42 7', '%d %d');
        $this->assertNotNull($ht);
        $this->assertSame(2, $ht->getNumElements());
        $this->assertNull(\PHPCompiler\ext\standard\SscanfJitHelper::parseToArray('', '%d'));
    }
}
