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
