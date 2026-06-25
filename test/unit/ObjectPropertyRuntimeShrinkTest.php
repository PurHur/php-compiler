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

    public function testEnumStringCastErrorMessageMatchesZend(): void
    {
        $msg = EnumCasePropertyJitHelper::enumStringCastErrorMessage('E');
        $this->assertSame('Object of class E could not be converted to string', $msg);
    }
}
