<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\NestedVmHashTableMethodLlvm;
use PHPUnit\Framework\TestCase;

/** Nested HashTable JIT method registry for php-in-PHP JitHelpers (#14601, #20533). */
final class NestedVmHashTableMethodLlvmTest extends TestCase
{
    public function testRegistersPadCopyAndGetNumElements(): void
    {
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('getnumelements'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('add'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('append'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('updateindex'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('padcopy'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('exportkeyvaluepairs'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('ispackedlist'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('comparespaceship'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('valuescopy'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('keyscopy'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('keysmatchingcopy'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('iterate'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('mergestringkeysfrom'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('find'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('findindex'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('unshiftprepend'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('shiftfirst'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('iteratekeyed'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('addindex'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('slicecopy'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('duplicate'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('unioncopy'));
    }

    /** Issue #20533 — thin AOT gate via isThinStandaloneAotMain, not StreamIo defer bag. */
    public function testKeysCopyValuesCopyGateOnThinStandaloneAotMain(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/NestedVmHashTableMethodLlvm.php');
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('StreamIoRuntime', $source);
        $this->assertStringContainsString('keyscopy', $source);
        $this->assertStringContainsString('valuescopy', $source);
    }
}
