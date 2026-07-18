<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayIsListJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_is_list() AOT routes through ArrayIsListJitHelper PHP via JitVmHelperLink (#6229, #13645, #18990). */
final class ArrayIsListRuntimeShrinkTest extends TestCase
{
    public function testArrayIsListRuntimeUsesJitHelperBridgeNotNativeLlvm(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayIsListRuntime.php');
        $this->assertStringContainsString('ArrayIsListJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $runtime);
        $this->assertStringContainsString('IS_NATIVE_ARRAY', $runtime);
        $this->assertStringNotContainsString('ArrayIsListNativeLlvm', $runtime);
        $this->assertStringNotContainsString('JitArrayIsList::invoke', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/ArrayIsListNativeLlvm.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_is_list.php');
        $this->assertStringContainsString('ArrayIsListRuntime::isList', $builtin);
        $this->assertStringNotContainsString('JitArrayIsList::invoke', $builtin);
    }

    public function testNestedHelperCoerceExtractsBoolFromHelperResult(): void
    {
        $coerce = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitNestedHelperCoerce.php');
        $this->assertStringContainsString('extractBoolFromHelperResult', $coerce);
        $this->assertMatchesRegularExpression(
            '/function coerceBridgeResult\(.*?extractBoolFromHelperResult/s',
            $coerce
        );
        // Must not route bool boxes through readLong (#8555 / #20652).
        $this->assertMatchesRegularExpression(
            '/function extractBoolFromHelperResult\(.*?int1.*?isValueBox/s',
            $coerce
        );
    }

    public function testArrayIsListJitHelperMatchesVmIsListSemantics(): void
    {
        $this->assertTrue(ArrayIsListJitHelper::isList(new HashTable()));

        $list = new HashTable();
        foreach ([1, 2] as $i => $raw) {
            $var = new Variable();
            $var->int($raw);
            $list->addIndex($i, $var);
        }
        $this->assertTrue(ArrayIsListJitHelper::isList($list));

        $assoc = new HashTable();
        $var = new Variable();
        $var->string('a');
        $assoc->add('x', $var);
        $this->assertFalse(ArrayIsListJitHelper::isList($assoc));
    }
}
