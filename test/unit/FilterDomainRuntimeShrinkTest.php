<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * filter_var FILTER_VALIDATE_DOMAIN JIT routes through FilterDomainJitHelper PHP
 * via JitVmHelperLink::ensureCompiled (#17407 / #24667 / peer #24650).
 */
final class FilterDomainRuntimeShrinkTest extends TestCase
{
    public function testFilterDomainJitHelperDelegatesToVmFilter(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/FilterDomainJitHelper.php');
        $this->assertStringContainsString('VmFilter::isValidDomain', $source);
    }

    public function testStringFilterDomainRoutesThroughFilterDomainJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFilterDomain.php');
        $this->assertStringContainsString('FilterDomainJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('putenv', $source);
        $this->assertLessThan(150, \substr_count($source, "\n"), 'StringFilterDomain must be a thin bridge');
    }

    public function testJitFilterRoutesThroughStringFilterDomain(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/JitFilter.php');
        $this->assertStringContainsString('StringFilterDomain::ensureLinked', $source);
    }

    public function testFilterDomainJitHelperSemanticsMatchVmFilter(): void
    {
        $this->assertSame('example.com', \PHPCompiler\ext\filter\FilterDomainJitHelper::validate('example.com'));
        // Loose mode rejects ".." / leading "."; spaces are allowed without FILTER_FLAG_HOSTNAME.
        $this->assertNull(\PHPCompiler\ext\filter\FilterDomainJitHelper::validate('...'));
        $hostname = \PHPCompiler\ext\filter\VmFilter::FILTER_FLAG_HOSTNAME;
        $this->assertSame('localhost', \PHPCompiler\ext\filter\FilterDomainJitHelper::validate('localhost', $hostname));
        $this->assertNull(\PHPCompiler\ext\filter\FilterDomainJitHelper::validate('not a domain', $hostname));
    }

    public function testSpineBundleIncludesFilterDomainJitHelper(): void
    {
        $spine = (string) \file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FilterDomainJitHelper.php', $spine);
        $this->assertStringContainsString('StringFilterDomain.php', $spine);
    }
}
