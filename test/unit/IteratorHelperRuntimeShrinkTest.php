<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Foreach hashtable JIT routes through VmIteratorForeach PHP SSOT (#10080). */
final class IteratorHelperRuntimeShrinkTest extends TestCase
{
    public function testIteratorHelperIsThinTrampoline(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/IteratorHelper.php');
        $this->assertStringContainsString('VmIteratorForeach', $source);
        $this->assertStringNotContainsString('foreach_packed_body', $source);
        $this->assertStringNotContainsString('foreach_obj_init', $source);
        $this->assertStringNotContainsString('copyValueEntryToBox', $source);
        $this->assertLessThanOrEqual(60, substr_count($source, "\n") + 1);
    }

    public function testVmIteratorForeachOwnsHashtableLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/VmIteratorForeach.php');
        $this->assertStringContainsString('compileValidHashtable', $source);
        $this->assertStringContainsString('compileValidObjectKeys', $source);
        $this->assertStringContainsString('stringKeyNodeAt', $source);
        $this->assertGreaterThan(600, substr_count($source, "\n") + 1);
    }
}
