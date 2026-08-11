<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** CastHelper routes casts through PHP-backed helpers, not monolithic LLVM (#10046, #10244). */
final class CastRuntimeShrinkTest extends TestCase
{
    public function testCastArrayRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CastArrayRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringContainsString('SplArrayCastJitHelper', $source);
        $this->assertStringNotContainsString('boolYieldsEmptyArray', $source);
    }

    public function testCastHelperIsThinDispatcher(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/CastHelper.php');
        $this->assertStringContainsString('CastArrayNativeJit', $source);
        $this->assertStringContainsString('CastObjectNativeJit', $source);
        $this->assertStringContainsString('CastUnsetJit', $source);
        $this->assertStringContainsString('CastArrayValueBoxJit', $source);
        $this->assertLessThanOrEqual(45, substr_count($source, "\n") + 1);
    }

    public function testCastJitHelperAlignsWithCastSupport(): void
    {
        // Zend convert_to_array wraps both bools (#30097); helper always declines empty.
        $this->assertFalse(\PHPCompiler\VM\CastJitHelper::boolYieldsEmptyArray(false));
        $this->assertFalse(\PHPCompiler\VM\CastJitHelper::boolYieldsEmptyArray(true));

        $false = new \PHPCompiler\VM\Variable(\PHPCompiler\VM\Variable::TYPE_BOOLEAN);
        $false->bool(false);
        $fromFalse = \PHPCompiler\VM\CastSupport::toArray($false);
        $this->assertSame(1, $fromFalse->toArray()->getNumElements());
        $falsePairs = iterator_to_array($fromFalse->toArray()->iterateKeyed(true), false);
        $this->assertCount(1, $falsePairs);
        $this->assertFalse($falsePairs[0][1]->toBool());

        $true = new \PHPCompiler\VM\Variable(\PHPCompiler\VM\Variable::TYPE_BOOLEAN);
        $true->bool(true);
        $fromTrue = \PHPCompiler\VM\CastSupport::toArray($true);
        $this->assertSame(1, $fromTrue->toArray()->getNumElements());
        $truePairs = iterator_to_array($fromTrue->toArray()->iterateKeyed(true), false);
        $this->assertCount(1, $truePairs);
        $this->assertTrue($truePairs[0][1]->toBool());

        $null = new \PHPCompiler\VM\Variable(\PHPCompiler\VM\Variable::TYPE_NULL);
        $fromNull = \PHPCompiler\VM\CastSupport::toArray($null);
        $this->assertSame(0, $fromNull->toArray()->getNumElements());
    }

    public function testCastArraySharedUsesWrapNullForResourceObjectCast(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/CastArrayShared.php');
        $this->assertStringContainsString('wrapResourceInArray', $source);
        $this->assertStringContainsString('emitObjectOperandToArray', $source);
        $this->assertStringContainsString('wrapScalarInArray', $source);
    }

    public function testCastArrayCowDuplicateUsesHashTableDuplicateRuntime(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/HashTableDuplicateRuntime.php');
        $this->assertStringContainsString('HashTableCowLlvm::duplicate', $runtime);
        $this->assertStringContainsString('__hashtable__duplicate', $runtime);
    }
}
