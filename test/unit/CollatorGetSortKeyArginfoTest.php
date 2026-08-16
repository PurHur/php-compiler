<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\BuiltinInternalArgInfo;
use PHPCompiler\BuiltinParamNames;
use PHPUnit\Framework\TestCase;

/** Collator::getSortKey Reflection arginfo tables (#28785). */
final class CollatorGetSortKeyArginfoTest extends TestCase
{
    public function testMethodArginfoTables(): void
    {
        $this->assertSame(1, BuiltinParamNames::paramCountForInternalMethod('Collator', 'getSortKey'));
        $this->assertSame(1, BuiltinParamNames::requiredParamCountForInternalMethod('Collator', 'getSortKey'));
        $this->assertSame(
            ['string'],
            BuiltinParamNames::forClassMethod('collator::getsortkey')
        );
        $this->assertSame(
            'string',
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('collator', 'getsortkey', 0)
        );
        $this->assertNull(
            BuiltinInternalArgInfo::stubParamTypeOverrideForClassMethod('collator', 'getsortkey', 1)
        );
        // Named-arg dispatch uses the OOP stub name, not procedural $str (#28785).
        $names = BuiltinParamNames::forClassMethod('collator::getsortkey');
        $this->assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'string', 'Collator::getSortKey'));
        $this->assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str', 'Collator::getSortKey'));
        $this->assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'coll', 'Collator::getSortKey'));
    }
}
