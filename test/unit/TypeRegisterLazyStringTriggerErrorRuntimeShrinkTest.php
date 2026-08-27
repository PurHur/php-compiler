<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::register always-on StringTriggerError (#35392 / peer #34513 initialize).
 *
 * HashTable::implement declares undef-key ABIs + ensureLinked at entry; call sites
 * already ensure before lookup. Thin AOT must not NestedJIT trigger_error during
 * Type::register (#31894 / #32122 .1 mint class).
 */
final class TypeRegisterLazyStringTriggerErrorRuntimeShrinkTest extends TestCase
{
    public function testTypeRegisterDropsEagerStringTriggerError(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#35392', $type);

        $regPos = strpos($type, 'public function register(): void');
        $this->assertNotFalse($regPos);
        $initPos = strpos($type, 'public function initialize(): void');
        $this->assertNotFalse($initPos);
        $regBody = substr($type, $regPos, $initPos - $regPos);

        $this->assertStringNotContainsString(
            'StringTriggerError::ensureLinked($this->context)',
            $regBody,
            'Type::register must not eagerly StringTriggerError::ensureLinked (#35392)'
        );
        $this->assertStringNotContainsString(
            'StringTriggerError::declareUndefinedArrayKeyAbis($this->context)',
            $regBody,
            'Type::register must not eagerly declareUndefinedArrayKeyAbis (#35392)'
        );
        $this->assertStringNotContainsString(
            'StringTriggerError::ensureStandaloneBodies($this->context)',
            $regBody,
            'Type::register must not eagerly StringTriggerError::ensureStandaloneBodies (#35392)'
        );
    }

    public function testHashTableImplementEnsuresStringTriggerErrorBeforeLookups(): void
    {
        $ht = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString('#35392', $ht);
        $implPos = strpos($ht, 'public function implement(): void');
        $this->assertNotFalse($implPos);
        $implBody = substr($ht, $implPos, 600);
        $this->assertStringContainsString(
            'StringTriggerError::declareUndefinedArrayKeyAbis($this->context)',
            $implBody,
            'HashTable::implement must declare undef-key ABIs before lookups (#35392)'
        );
        $this->assertStringContainsString(
            'StringTriggerError::ensureLinked($this->context)',
            $implBody,
            'HashTable::implement must ensureLinked before undef-key lookup (#35392)'
        );
        $declPos = strpos($implBody, 'StringTriggerError::declareUndefinedArrayKeyAbis');
        $ensurePos = strpos($implBody, 'StringTriggerError::ensureLinked');
        $allocPos = strpos($implBody, 'implementAlloc');
        $this->assertNotFalse($declPos);
        $this->assertNotFalse($ensurePos);
        $this->assertNotFalse($allocPos);
        $this->assertLessThan($allocPos, $declPos);
        $this->assertLessThan($allocPos, $ensurePos);
    }

    public function testJitScalarEnumCoerceEnsuresBeforeLookup(): void
    {
        $coerce = (string) file_get_contents(__DIR__.'/../../ext/standard/JitScalarEnumCoerce.php');
        $this->assertStringContainsString('#35392', $coerce);
        $warnPos = strpos($coerce, 'function emitObjectScalarWarning');
        $this->assertNotFalse($warnPos);
        $warnBody = substr($coerce, $warnPos, 2000);
        $ensurePos = strpos($warnBody, 'StringTriggerError::ensureLinked');
        $lookupPos = strpos($warnBody, '__compiler_trigger_error');
        $this->assertNotFalse($ensurePos);
        $this->assertNotFalse($lookupPos);
        $this->assertLessThan(
            $lookupPos,
            $ensurePos,
            'emitObjectScalarWarning must ensureLinked before lookup (#35392 / WeakRef NestedJIT)'
        );
    }

    public function testNoNewRuntimeCForTypeRegisterTriggerErrorShrink(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/trigger_error.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/trigger_error.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/undefined_array_key.c');
    }
}
