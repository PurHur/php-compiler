<?php

declare(strict_types=1);

namespace test\unit;

use PHPCompiler\AOT\HelperRuntimeCommon;
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

    public function testLinkDisabledWithoutOptIn(): void
    {
        $this->assertNull(HelperRuntimeCommon::linkObject());
    }

    public function testOptInRequiredForLink(): void
    {
        putenv(HelperRuntimeCommon::ENV.'=1');
        if (HelperRuntimeCommon::commonObjectIsLinkable()) {
            $this->assertSame(HelperRuntimeCommon::commonObjectPath(), HelperRuntimeCommon::linkObject());
        } else {
            $this->assertNull(HelperRuntimeCommon::linkObject());
        }
    }

    public function testOptOutViaEnv(): void
    {
        putenv(HelperRuntimeCommon::ENV.'=0');
        $this->assertFalse(HelperRuntimeCommon::isLinkEnabled());
    }
}
