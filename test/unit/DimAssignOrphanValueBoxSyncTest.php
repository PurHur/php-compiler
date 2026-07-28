<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT dim-write lvalues keep an orphan __value__ box; assign must sync it after HT commit
 * so `$r0 = $a[0] = 99` and array-literal packing see the expression value (#24055).
 * Nested [$a] packing needs setValueBoxAtIndex hashtable dispatch (#24055).
 */
final class DimAssignOrphanValueBoxSyncTest extends TestCase
{
    public function testAssignOperandSyncsDimWriteOrphanBox(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        $this->assertStringContainsString('syncDimWriteOrphanValueBox', $jit);
        $this->assertStringContainsString('#24055', $jit);
    }

    public function testSetValueBoxAtIndexDispatchesHashtable(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableWriteLlvm.php');
        $this->assertMatchesRegularExpression(
            '/function setValueBoxAtIndex[\s\S]*__hashtable__setHashtableAt/',
            $src
        );
        $this->assertStringContainsString('Nested arrays in value boxes must not fall through to setLongAt (#24055', $src);
    }
}
