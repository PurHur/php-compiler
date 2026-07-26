<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * NestedJIT *JitHelper int params may be by-value {@see __value__} while ABI bridges pass bare i64.
 * coerceArgForHelper must box integers (bootstrap-selfhost-link module verify).
 */
final class NestedHelperCoerceIntBoxTest extends TestCase
{
    public function testCoerceArgBoxesIntegerIntoValueForHelper(): void
    {
        $coerce = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitNestedHelperCoerce.php');
        $this->assertStringContainsString('function coerceArgForHelper', $coerce);
        $this->assertMatchesRegularExpression(
            '/isValueBoxType\(\$context,\s*\$wantTy\)\s*&&\s*Type::KIND_INTEGER\s*===\s*\$haveTy->getKind\(\)/',
            $coerce
        );
        $this->assertStringContainsString('JitValueBox::writeLong', $coerce);
        $this->assertStringContainsString('JitValueBox::writeBool', $coerce);
    }

    public function testHttpResponseAndStreamBucketBridgesUseCallHelper(): void
    {
        $http = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/HttpResponseRuntime.php');
        $this->assertStringContainsString('JitNestedHelperCoerce::callHelper', $http);
        $this->assertStringContainsString('extractLongFromHelperResult', $http);

        $bucket = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamBucketKernel.php');
        $this->assertStringContainsString('JitNestedHelperCoerce::callHelper', $bucket);
        $this->assertStringContainsString('coerceBridgeResult', $bucket);
    }

    public function testStaticHashtablePropertyStoreUsesReadHashtable(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/ObjectStaticPropertyLlvm.php');
        $this->assertStringContainsString('TYPE_HASHTABLE === $propertyType', $source);
        $this->assertStringContainsString('__value__readHashtable', $source);
        $this->assertStringContainsString('TYPE_HASHTABLE === $entry[\'type\']', $source);
    }

    public function testFloatToIntPrecisionWarningRestoresInsertBlock(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitIntdiv.php');
        $this->assertStringContainsString('tryGetInsertBlock', $source);
        $this->assertStringContainsString('restoreInsertBlock', $source);
        $this->assertStringContainsString('StringTriggerErrorJit::implement', $source);
    }
}
