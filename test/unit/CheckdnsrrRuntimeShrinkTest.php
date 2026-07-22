<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CheckdnsrrJitHelper;
use PHPCompiler\ext\standard\VmDns;
use PHPUnit\Framework\TestCase;

/**
 * CheckdnsrrRuntime NestedJIT via JitVmHelperLink::ensureCompiled (#22355 / peer #22313).
 * Must route through CheckdnsrrJitHelper PHP, not libc res_query LLVM (#9379).
 */
final class CheckdnsrrRuntimeShrinkTest extends TestCase
{
    public function testCheckdnsrrRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CheckdnsrrRuntime.php');
        $this->assertStringContainsString('CheckdnsrrJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('res_init', $source);
        $this->assertStringNotContainsString('res_query', $source);
        $this->assertStringNotContainsString('DNS_TYPES', $source);
        $this->assertStringNotContainsString('emitResolveQtype', $source);
        $this->assertLessThan(120, substr_count($source, "\n") + 1);
    }

    public function testCheckdnsrrJitHelperDelegatesToVmDns(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/CheckdnsrrJitHelper.php');
        $this->assertStringContainsString('VmDns::checkdnsrr', $source);
        $this->assertSame(VmDns::checkdnsrr('localhost', 'A'), CheckdnsrrJitHelper::check('localhost', 'A'));
    }

    public function testJitCheckdnsrrUsesRuntimeBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitCheckdnsrr.php');
        $this->assertStringContainsString('CheckdnsrrRuntime::ensureLinked', $source);
        $this->assertStringContainsString('__compiler_checkdnsrr', $source);
    }
}
