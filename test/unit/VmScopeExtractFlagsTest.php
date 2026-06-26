<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmScope;
use PHPUnit\Framework\TestCase;

/** extract() EXTR_* flag matrix in VmScope PHP (#3471, php-src ext/standard/array.c). */
final class VmScopeExtractFlagsTest extends TestCase
{
    public function testPrefixVarNameInsertsUnderscore(): void
    {
        $this->assertSame('all_foo', VmScope::prefixVarName('all', 'foo'));
        $this->assertSame('all__foo', VmScope::prefixVarName('all_', 'foo'));
    }

    public function testExtrConstantsMatchStdlib(): void
    {
        $this->assertSame(0, VmScope::EXTR_OVERWRITE);
        $this->assertSame(6, VmScope::EXTR_IF_EXISTS);
        $this->assertSame(0x100, VmScope::EXTR_REFS);
    }
}
