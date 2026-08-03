<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\VmLazyObject;
use PHPUnit\Framework\TestCase;

/** LazyObjectHelper routes LLVM through LazyObjectHelperLlvm + VmLazyObject PHP guards (#10267). */
final class LazyObjectHelperRuntimeShrinkTest extends TestCase
{
    public function testLazyObjectHelperIsThinTrampoline(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/LazyObjectHelper.php');
        $this->assertStringContainsString('LazyObjectHelperLlvm::registerLazyObject', $helper);
        $this->assertStringContainsString('LazyObjectHelperLlvm::emitEnsureInitialized', $helper);
        $this->assertStringNotContainsString('emitInitBody', $helper);
        $this->assertStringNotContainsString('lazy_init_proxy_', $helper);
        $this->assertLessThan(70, substr_count($helper, "\n"));
    }

    public function testLazyObjectHelperLlvmUsesVmLazyObjectFieldNames(): void
    {
        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/LazyObjectHelperLlvm.php');
        $this->assertStringContainsString('VmLazyObject::', $llvm);
        $this->assertStringNotContainsString("'lazy_pending'", $llvm);
        $this->assertStringNotContainsString("'lazy_ghost'", $llvm);
    }

    /** #27302 — pending/ghost flags are i8; icmp vs i32 0 fails AOT module verify. */
    public function testLazyFlagIcmpUsesInt8Zero(): void
    {
        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/LazyObjectHelperLlvm.php');
        $this->assertMatchesRegularExpression(
            '/FIELD_LAZY_PENDING.*?icmp\(\s*Builder::INT_NE,\s*\$pending,\s*\$i8->constInt\(0/s',
            $llvm
        );
        $this->assertMatchesRegularExpression(
            '/FIELD_LAZY_GHOST.*?icmp\(\s*Builder::INT_NE,\s*\$ghost,\s*\$i8->constInt\(0/s',
            $llvm
        );
        $this->assertStringNotContainsString(
            '$i32->constInt(0, false)',
            $llvm,
            'lazy flag icmp must not compare i8 loads to i32 0'
        );
    }

    /** #27302 — detach lazy flags before invoking the initializer (no re-entrant init). */
    public function testEmitDetachLazyFlagsBeforeInitializerCall(): void
    {
        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/LazyObjectHelperLlvm.php');
        $this->assertStringContainsString('emitDetachLazyFlags', $llvm);
        $ghostCallPos = strpos($llvm, 'applyLazyGhostPropertyDefaults');
        $detachPos = strpos($llvm, 'self::emitDetachLazyFlags($context, $obj);');
        $proxyCallPos = strpos($llvm, '$proxy->call($context, $thisVar)');
        $this->assertNotFalse($ghostCallPos);
        $this->assertNotFalse($detachPos);
        $this->assertNotFalse($proxyCallPos);
        $this->assertGreaterThan($ghostCallPos, $detachPos);
        $this->assertGreaterThan($detachPos, $proxyCallPos);
    }

    public function testVmLazyObjectHeaderFields(): void
    {
        $this->assertSame(
            ['lazy_pending', 'lazy_ghost', 'lazy_init_index', 'constructed'],
            VmLazyObject::objectHeaderLazyFields()
        );
    }
}
