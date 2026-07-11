<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\EnumCasePropertyJitHelper;
use PHPUnit\Framework\TestCase;

/** Object property enum-case LLVM routes through EnumCasePropertyJitHelper PHP (#9938). */
final class ObjectPropertyRuntimeShrinkTest extends TestCase
{
    public function testEnumCasePropertyJitHelperDefinesSlotIndices(): void
    {
        $this->assertSame(0, EnumCasePropertyJitHelper::SLOT_NAME);
        $this->assertSame(1, EnumCasePropertyJitHelper::SLOT_VALUE);
        $this->assertSame(0, EnumCasePropertyJitHelper::slotIndexForBuiltinProperty('name'));
        $this->assertSame(1, EnumCasePropertyJitHelper::slotIndexForBuiltinProperty('value'));
    }

    public function testObjectMonolithDelegatesEnumPropertyFetch(): void
    {
        $object = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/Object_.php');
        $this->assertStringContainsString('ObjectEnumCasePropertyLlvm::enumCasePropertyFetch', $object);
        $this->assertStringContainsString('EnumCasePropertyJitHelper::singletonGlobalName', $object);
        $this->assertStringNotContainsString('private function enumCasePropertyFetch', $object);
        $this->assertStringNotContainsString('private function propertyFetchEnumCaseRuntimeDispatch', $object);
    }

    public function testObjectMonolithDelegatesEnumStringCastErrors(): void
    {
        $object = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/Object_.php');
        $this->assertStringContainsString('ObjectEnumStringCastLlvm::emitEnumObjectStringErrorIfMatches', $object);
        $this->assertStringNotContainsString('private function emitEnumClassIdStringCastErrorChain', $object);
        $this->assertStringNotContainsString('private function knownEnumClassIdToName', $object);
    }

    public function testObjectMonolithDelegatesExitStatusLowering(): void
    {
        $object = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/Object_.php');
        $this->assertStringContainsString('ObjectExitStatusLlvm::emitExitStatusObjectGuard', $object);
        $this->assertStringContainsString('ObjectExitStatusLlvm::emitExitStatusFromEnumCaseObject', $object);
        $this->assertStringNotContainsString('private function enumCaseBackingLong', $object);
        $this->assertStringNotContainsString('exit_status_obj_enum_', $object);
    }

    public function testObjectMonolithDelegatesDestructorLowering(): void
    {
        $object = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/Object_.php');
        $this->assertStringContainsString('ObjectDestructorLlvm::implementInvokeDestructor', $object);
        $this->assertStringNotContainsString('private function emitDestructDispatchForObject', $object);
        $this->assertStringNotContainsString('private function emitDestructMagicCallForClass', $object);
    }

    public function testObjectMonolithDelegatesStaticPropertyInit(): void
    {
        $object = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/Object_.php');
        $this->assertStringContainsString('ObjectStaticPropertyInitLlvm::scalarInitializer', $object);
        $this->assertStringContainsString('ObjectStaticPropertyInitLlvm::initValueNull', $object);
        $this->assertStringNotContainsString('private function initStaticValuePropertyNull', $object);
        $this->assertStringNotContainsString('private function staticPropertyScalarInitializer', $object);
    }

    public function testObjectMonolithDelegatesInstancePropertyFetch(): void
    {
        $object = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/Object_.php');
        $this->assertStringContainsString('ObjectInstancePropertyLlvm::propertyFetchOrdinary', $object);
        $this->assertStringContainsString('ObjectInstancePropertyLlvm::boxFetchedPropertyIntoValue', $object);
        $this->assertStringNotContainsString('private function propertyFetchOrdinary', $object);
        $this->assertStringNotContainsString('private function boxFetchedPropertyIntoValue', $object);
    }

    public function testEnumStringCastErrorMessageMatchesZend(): void
    {
        $msg = EnumCasePropertyJitHelper::enumStringCastErrorMessage('E');
        $this->assertSame('Object of class E could not be converted to string', $msg);
    }
}
