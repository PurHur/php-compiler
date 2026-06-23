<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CheckdnsrrJitHelper;
use PHPCompiler\ext\standard\VmDns;
use PHPUnit\Framework\TestCase;

/** CheckdnsrrRuntime must route through CheckdnsrrJitHelper PHP, not libc res_query LLVM (#9379). */
final class CheckdnsrrRuntimeShrinkTest extends TestCase
{
    public function testCheckdnsrrRuntimeUsesJitHelperNotLibcLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CheckdnsrrRuntime.php');
        $this->assertStringContainsString('CheckdnsrrJitHelper', $source);
        $this->assertStringNotContainsString('res_init', $source);
        $this->assertStringNotContainsString('res_query', $source);
        $this->assertStringNotContainsString('DNS_TYPES', $source);
        $this->assertStringNotContainsString('emitResolveQtype', $source);
        $this->assertLessThan(160, substr_count($source, "\n"));
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
