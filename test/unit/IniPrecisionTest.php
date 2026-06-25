<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\ext\standard\IniJitHelper;
use PHPCompiler\ext\standard\VmIni;

/** precision INI parity (issue #11841, ext/standard/ini.c). */
final class IniPrecisionTest extends TestCase
{
    public function testVmIniDefaultPrecision(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertSame('14', VmIni::get($ctx, 'precision'));
        $this->assertSame(14, VmIni::getPrecision());
    }

    public function testVmIniPrecisionRoundTrip(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertSame('14', VmIni::set($ctx, 'precision', '8'));
        $this->assertSame('8', VmIni::get($ctx, 'precision'));
        VmIni::restore($ctx, 'precision');
        $this->assertSame('14', VmIni::get($ctx, 'precision'));
    }

    public function testIniJitHelperPrecisionRoundTrip(): void
    {
        $this->assertSame('14', IniJitHelper::iniGet('precision'));
        $this->assertSame('14', IniJitHelper::iniSet('precision', '8'));
        $this->assertSame('8', IniJitHelper::iniGet('precision'));
        IniJitHelper::iniRestore('precision');
        $this->assertSame('14', IniJitHelper::iniGet('precision'));
    }
}
