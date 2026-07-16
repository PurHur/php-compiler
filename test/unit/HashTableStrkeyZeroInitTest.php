<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * #19627: strkey/objkey nodes must be zeroed before writeString/writeNull.
 */
final class HashTableStrkeyZeroInitTest extends TestCase
{
    public function testStrkeyMallocZeroesValueTypeBeforeWrite(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString('function mallocZeroedNode', $source);
        $this->assertMatchesRegularExpression(
            '/mallocZeroedNode[\s\S]*memset/',
            $source
        );
        $rawMallocCount = preg_match_all(
            '/\$newNode = \$this->context->memory->malloc\(\$nodeType\);/',
            $source
        );
        // Only the helper itself may call raw malloc for key nodes.
        $this->assertSame(1, $rawMallocCount);
        $this->assertGreaterThanOrEqual(
            9,
            substr_count($source, 'mallocZeroedNode($nodeType)')
        );
    }
}
