<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\HashEqualsJitHelper;
use PHPCompiler\ext\standard\VmHash;
use PHPUnit\Framework\TestCase;

/** StringHashEquals routes through HashEqualsJitHelper PHP not LLVM compare loop (#9164). */
final class StringHashEqualsRuntimeShrinkTest extends TestCase
{
    public function testStringHashEqualsRoutesThroughHashEqualsJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHashEquals.php');
        $this->assertStringContainsString('HashEqualsJitHelper', $source);
        $this->assertStringNotContainsString('hash_equals_loop_head', $source);
        $this->assertStringNotContainsString('stringData', $source);
        $this->assertLessThan(120, \substr_count($source, "\n") + 1);
    }

    public function testHashEqualsJitHelperDelegatesToVmHash(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/HashEqualsJitHelper.php');
        $this->assertStringContainsString('VmHash::equals', $source);
    }

    public function testHashEqualsJitHelperSemanticsMatchVmHash(): void
    {
        $this->assertTrue(HashEqualsJitHelper::equals('abc', 'abc'));
        $this->assertFalse(HashEqualsJitHelper::equals('abc', 'abd'));
        $this->assertFalse(HashEqualsJitHelper::equals('ab', 'abc'));
        $this->assertSame(
            VmHash::equals('secret', 'secret'),
            HashEqualsJitHelper::equals('secret', 'secret')
        );
    }
}
