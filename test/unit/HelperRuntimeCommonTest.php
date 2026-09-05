<?php

declare(strict_types=1);

namespace test\unit;

use PHPCompiler\AOT\HelperRuntimeCommon;
use PHPCompiler\AOT\HelperRuntimeCache;
use PHPUnit\Framework\TestCase;

final class HelperRuntimeCommonTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(HelperRuntimeCommon::ENV);
        unset($_ENV[HelperRuntimeCommon::ENV], $_SERVER[HelperRuntimeCommon::ENV]);
    }

    public function testSharedRuntimeSymbolClassification(): void
    {
        $this->assertTrue(HelperRuntimeCommon::isSharedRuntimeSymbol('__value__writeLong'));
        $this->assertTrue(HelperRuntimeCommon::isSharedRuntimeSymbol('__hashtable__setLongAt'));
        $this->assertTrue(HelperRuntimeCommon::isSharedRuntimeSymbol('phpc_jit_clear_throw_pending'));
        $this->assertFalse(HelperRuntimeCommon::isSharedRuntimeSymbol('PHPCompiler_ext_standard_FooJitHelper__bar'));
        $this->assertFalse(HelperRuntimeCommon::isSharedRuntimeSymbol('main'));
    }

    public function testLinkDisabledWithoutOptInWhenCorpusLacksGcSections(): void
    {
        if (HelperRuntimeCache::prelinkedCorpusHasGcSections()) {
            $this->markTestSkipped('prelinked corpus already has gc sections');
        }
        $this->assertNull(HelperRuntimeCommon::linkObject());
    }

    public function testOptInForcesLinkWhenCommonAndGcCorpus(): void
    {
        putenv(HelperRuntimeCommon::ENV.'=1');
        if (!HelperRuntimeCommon::commonObjectIsLinkable()) {
            $this->markTestSkipped('common.o not linkable');
        }
        if (!HelperRuntimeCache::prelinkedCorpusHasGcSections()) {
            $this->markTestSkipped('prelinked corpus lacks gc sections');
        }
        $this->assertSame(HelperRuntimeCommon::commonObjectPath(), HelperRuntimeCommon::linkObject());
    }

    public function testAutoLinkWhenPrelinkedCorpusHasGcSections(): void
    {
        if (!HelperRuntimeCache::prelinkedCorpusHasGcSections()) {
            $this->markTestSkipped('prelinked corpus lacks gc sections — run emit --force --prelink');
        }
        if (!HelperRuntimeCommon::commonObjectIsLinkable()) {
            $this->markTestSkipped('common.o not linkable');
        }
        $this->assertTrue(HelperRuntimeCommon::isLinkEnabled());
        $this->assertSame(HelperRuntimeCommon::commonObjectPath(), HelperRuntimeCommon::linkObject());
    }

    public function testOptOutViaEnv(): void
    {
        putenv(HelperRuntimeCommon::ENV.'=0');
        $this->assertFalse(HelperRuntimeCommon::isLinkEnabled());
    }

    /**
     * Monolithic committed units stay linkable while COMMON is off (#36401).
     * gc_sections units without COMMON must not be selected (aot-smoke SIGSEGV).
     */
    public function testMonolithicPrelinkedUnitSafeWithoutCommon(): void
    {
        putenv(HelperRuntimeCommon::ENV.'=0');
        unset($_ENV[HelperRuntimeCommon::ENV], $_SERVER[HelperRuntimeCommon::ENV]);
        $dir = HelperRuntimeCache::prelinkedUnitsDir().'/'.HelperRuntimeCache::slugFor('/ext/ctype/CtypeJitHelper.php');
        if (!HelperRuntimeCache::unitObjectIsLinkable($dir)) {
            $this->markTestSkipped('ctype helper unit.o missing from prelinked cache');
        }
        if (HelperRuntimeCache::prelinkedCorpusHasGcSections()) {
            $this->markTestSkipped('prelinked corpus already migrated to gc_sections');
        }
        $this->assertTrue(
            HelperRuntimeCache::unitObjectIsSafeToLink($dir),
            'monolithic prelinked units must remain safe to link without COMMON'
        );
    }
}
