<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * HashTable sort/compare routes through JitStringCompare + StringNaturalCompare (#29019).
 */
final class HashTableCompareRuntimeShrinkTest extends TestCase
{
    public function testHashTableDoesNotDeclareEmptyStrnatLibcExterns(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString('ensureStringCompareAbis', $source);
        $this->assertStringContainsString('StringNaturalCompare::ensureStandaloneBodies', $source);
        $this->assertStringContainsString('StringStrcoll::ensureLinked', $source);
        $this->assertStringNotContainsString('ensureLibcStringCompare', $source);
        $this->assertStringNotContainsString("addFunction('strnatcmp'", $source);
        $this->assertStringNotContainsString("addFunction('strnatcasecmp'", $source);
        $this->assertStringNotContainsString("addFunction('strcmp'", $source);
    }

    public function testKsortStringKeysUseJitStringCompareNotLibcStrcmp(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString('JitStringCompare::strcmp', $source);
        $this->assertStringNotContainsString("lookupFunction('strcmp')", $source);
        $this->assertStringNotContainsString("lookupFunction('strcoll')", $source);
        $this->assertStringContainsString('StringStrcoll::ABI_STRCOLL', $source);
    }

    public function testNatsortStillUsesStringNaturalCompareAbi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString("lookupFunction('strnatcmp')", $source);
        $this->assertStringContainsString("lookupFunction('strnatcasecmp')", $source);
        $this->assertStringContainsString('StringNaturalCompare', $source);
    }
}
