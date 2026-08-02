<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** filter_var FILTER_VALIDATE_EMAIL JIT routes through FilterEmailJitHelper PHP (#9860). */
final class FilterEmailJitRuntimeShrinkTest extends TestCase
{
    public function testFilterEmailJitHelperDelegatesToFilterEmailValidate(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/FilterEmailJitHelper.php');
        $this->assertStringContainsString('FilterEmailValidate::isValid', $source);
        $this->assertStringNotContainsString('VmFilter::', $source);
    }

    public function testStringFilterEmailRoutesThroughFilterEmailJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFilterEmail.php');
        // #27068 — ABI bridges isValidInt (NestedJIT int 0/1; const path folds in JitFilter).
        $this->assertStringContainsString('FilterEmailValidate::isValidInt', $source);
        $this->assertStringNotContainsString('llvmIsLocalChar', $source);
        $this->assertStringNotContainsString('filter_email_find_at_head', $source);
        $this->assertLessThan(180, \substr_count($source, "\n"), 'StringFilterEmail must be a thin bridge');
    }

    public function testJitFilterFoldsConstEmailViaFilterEmailValidate(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/JitFilter.php');
        $this->assertStringContainsString('FilterEmailValidate::isValid', $source);
        $this->assertStringContainsString('compileTimeString', $source);
        $this->assertStringContainsString('#27068', $source);
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
        // Domain label rules (#22826).
        $this->assertNull(\PHPCompiler\ext\filter\FilterEmailJitHelper::validate('test@-example.com'));
        $this->assertNull(\PHPCompiler\ext\filter\FilterEmailJitHelper::validate('a@b..com'));
        $this->assertNull(\PHPCompiler\ext\filter\FilterEmailJitHelper::validate('test@example.com.'));
        $this->assertSame('user@ex--ample.com', \PHPCompiler\ext\filter\FilterEmailJitHelper::validate('user@ex--ample.com'));
    }
}
