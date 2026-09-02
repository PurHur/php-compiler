<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Packed __hashtable__ values allocation must match LLVM __value__ stride (#36214). */
final class HashTablePackedStride36214Test extends TestCase
{
    public function testGrowUsesValueStrideNotLegacySixteen(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString('private const PACKED_VALUE_STRIDE = 9;', $source);
        $this->assertStringContainsString('constInt(self::PACKED_VALUE_STRIDE, false)', $source);
        $this->assertStringNotContainsString('constInt(16, false)', $source);
    }
}
