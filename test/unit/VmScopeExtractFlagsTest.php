<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ScopeBuiltinJitHelper;
use PHPCompiler\ext\standard\VmScope;
use PHPUnit\Framework\TestCase;

/** extract() EXTR_* flag matrix in VmScope PHP (#3471, php-src ext/standard/array.c). */
final class VmScopeExtractFlagsTest extends TestCase
{
    public function testPrefixVarNameInsertsUnderscore(): void
    {
        $this->assertSame('all_foo', ScopeBuiltinJitHelper::prefixVarName('all', 'foo'));
        $this->assertSame('all__foo', ScopeBuiltinJitHelper::prefixVarName('all_', 'foo'));
        $this->assertSame('all_foo', VmScope::prefixVarName('all', 'foo'));
    }

    public function testResolveExtractFinalNamePrefixAll(): void
    {
        $this->assertSame(
            'all_foo',
            ScopeBuiltinJitHelper::resolveExtractFinalName('foo', false, VmScope::EXTR_PREFIX_ALL, 'all')
        );
    }

    /** EXTR_SKIP imports when varExists=false; skips when true (#24309). */
    public function testResolveExtractFinalNameExtrSkip(): void
    {
        $this->assertSame(
            'b',
            ScopeBuiltinJitHelper::resolveExtractFinalName('b', false, VmScope::EXTR_SKIP, null)
        );
        $this->assertNull(
            ScopeBuiltinJitHelper::resolveExtractFinalName('a', true, VmScope::EXTR_SKIP, null)
        );
    }

    /** EXTR_IF_EXISTS only imports when varExists=true (#24310). */
    public function testResolveExtractFinalNameExtrIfExists(): void
    {
        $this->assertNull(
            ScopeBuiltinJitHelper::resolveExtractFinalName('b', false, VmScope::EXTR_IF_EXISTS, null)
        );
        $this->assertSame(
            'a',
            ScopeBuiltinJitHelper::resolveExtractFinalName('a', true, VmScope::EXTR_IF_EXISTS, null)
        );
    }

    /** EXTR_PREFIX_IF_EXISTS: set → prefix; absent → unprefixed (#24330). */
    public function testResolveExtractFinalNameExtrPrefixIfExists(): void
    {
        $this->assertSame(
            'p_a',
            ScopeBuiltinJitHelper::resolveExtractFinalName('a', true, VmScope::EXTR_PREFIX_IF_EXISTS, 'p')
        );
        $this->assertSame(
            'b',
            ScopeBuiltinJitHelper::resolveExtractFinalName('b', false, VmScope::EXTR_PREFIX_IF_EXISTS, 'p')
        );
    }

    public function testExtrConstantsMatchStdlib(): void
    {
        $this->assertSame(0, VmScope::EXTR_OVERWRITE);
        $this->assertSame(6, VmScope::EXTR_IF_EXISTS);
        $this->assertSame(0x100, VmScope::EXTR_REFS);
    }

    public function testExtractArrayArgIsByRef(): void
    {
        $this->assertSame([0], \PHPCompiler\BuiltinByRefParams::forFunction('extract'));
        $this->assertTrue(\PHPCompiler\BuiltinByRefParams::isByRefArg('extract', 0));
        $this->assertFalse(\PHPCompiler\BuiltinByRefParams::isByRefArg('extract', 1));
    }
}
