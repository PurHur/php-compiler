<?php

declare(strict_types=1);

namespace test\unit;

use PHPCompiler\JIT\AotDebugSymbols;
use PHPCompiler\JIT\AotGcSections;
use PHPUnit\Framework\TestCase;

final class AotGcSectionsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(AotGcSections::ENV);
        putenv(AotGcSections::STRIP_SUPPRESS_ENV);
        putenv(AotDebugSymbols::ENV);
        unset(
            $_ENV[AotGcSections::ENV],
            $_SERVER[AotGcSections::ENV],
            $_ENV[AotGcSections::STRIP_SUPPRESS_ENV],
            $_SERVER[AotGcSections::STRIP_SUPPRESS_ENV],
            $_ENV[AotDebugSymbols::ENV],
            $_SERVER[AotDebugSymbols::ENV],
        );
    }

    public function testEnabledByDefault(): void
    {
        $this->assertTrue(AotGcSections::isEnabled());
    }

    public function testOptOutViaEnv(): void
    {
        putenv(AotGcSections::ENV.'=0');
        $this->assertFalse(AotGcSections::isEnabled());
    }

    public function testStripByDefault(): void
    {
        $this->assertTrue(AotGcSections::stripAtLink());
        $this->assertSame('-s ', AotGcSections::linkStripFlag());
    }

    public function testStripSuppressedForDebugSymbols(): void
    {
        AotDebugSymbols::enable();
        $this->assertFalse(AotGcSections::stripAtLink());
        $this->assertSame('', AotGcSections::linkStripFlag());
    }

    public function testStripSuppressedForPhpcDebugSymbolsEnv(): void
    {
        putenv(AotGcSections::STRIP_SUPPRESS_ENV.'=1');
        $this->assertFalse(AotGcSections::stripAtLink());
    }

    public function testLinkGcSectionsFlags(): void
    {
        $this->assertSame(' --gc-sections ', AotGcSections::linkGcSectionsFlag(false));
        $this->assertSame(' -Wl,--gc-sections ', AotGcSections::linkGcSectionsFlag(true));
        putenv(AotGcSections::ENV.'=0');
        $this->assertSame('', AotGcSections::linkGcSectionsFlag(false));
    }

    public function testLinkGcSectionsSkippedForGcSectionHelperUnitsWithoutCommon(): void
    {
        putenv(\PHPCompiler\AOT\HelperRuntimeCommon::ENV.'=0');
        $monolithic = '/tmp/monolithic-helper-unit.o';
        $this->assertSame(' -Wl,--gc-sections ', AotGcSections::linkGcSectionsFlagForHelperLink(true, [$monolithic]));
        $perFunction = \dirname(__DIR__, 2).'/prelinked/helper-runtime/x86_64-linux/units/ext_ctype_CtypeJitHelper_php/unit.o';
        if (!\PHPCompiler\AOT\HelperRuntimeCache::unitObjectHasPerFunctionSections($perFunction)) {
            $this->markTestSkipped('committed anchor unit lacks per-function sections');
        }
        $this->assertSame('', AotGcSections::linkGcSectionsFlagForHelperLink(true, [$perFunction]));
    }
}
