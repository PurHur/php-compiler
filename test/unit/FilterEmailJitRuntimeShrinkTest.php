<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** filter_var FILTER_VALIDATE_EMAIL JIT routes through FilterEmailJitHelper PHP (#9860). */
final class FilterEmailJitRuntimeShrinkTest extends TestCase
{
    public function testFilterEmailJitHelperDelegatesToVmFilter(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/FilterEmailJitHelper.php');
        $this->assertStringContainsString('VmFilter::isValidEmailSubset', $source);
    }

    public function testStringFilterEmailRoutesThroughFilterEmailJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFilterEmail.php');
        $this->assertStringContainsString('FilterEmailJitHelper', $source);
        $this->assertStringNotContainsString('llvmIsLocalChar', $source);
        $this->assertStringNotContainsString('filter_email_find_at_head', $source);
        $this->assertLessThan(160, \substr_count($source, "\n"), 'StringFilterEmail must be a thin bridge');
    }

    public function testJitFilterRoutesThroughStringFilterEmail(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/JitFilter.php');
        $this->assertStringContainsString('StringFilterEmail::ensureLinked', $source);
    }

    public function testFilterEmailJitHelperSemanticsMatchVmFilter(): void
    {
        $this->assertSame('user@example.com', \PHPCompiler\ext\filter\FilterEmailJitHelper::validate('user@example.com'));
        $this->assertSame('a@b.co', \PHPCompiler\ext\filter\FilterEmailJitHelper::validate('a@b.co'));
        $this->assertNull(\PHPCompiler\ext\filter\FilterEmailJitHelper::validate(''));
        $this->assertNull(\PHPCompiler\ext\filter\FilterEmailJitHelper::validate('bad'));
        $this->assertNull(\PHPCompiler\ext\filter\FilterEmailJitHelper::validate('bad@'));
        $this->assertNull(\PHPCompiler\ext\filter\FilterEmailJitHelper::validate('@example.com'));
    }
}
