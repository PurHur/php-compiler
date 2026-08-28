<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop HashTable::implement always-on NestedJIT for undefined-array-key ABIs
 * (#35648 / peer #35392 Type::register lazy batch, #35626 strcoll pattern).
 *
 * Thin hello-world AOT must not NestedJIT trigger_error during HashTable init —
 * leftover NestedJIT vs Runtime ABI drift mints undefined_array_key_warning_*.1
 * (#31894 / #32122).
 */
final class HashTableLazyUndefinedArrayKeyRuntimeShrinkTest extends TestCase
{
    public function testHashTableImplementDropsEagerUndefinedArrayKeyEnsureLinked(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString('#35648', $source);
        $pos = strpos($source, 'public function implement(): void');
        $this->assertNotFalse($pos);
        $next = strpos($source, 'private function ensureLibcStrtol', $pos);
        $this->assertNotFalse($next);
        $body = substr($source, $pos, $next - $pos);

        foreach ([
            'StringTriggerError::declareUndefinedArrayKeyAbis',
            'StringTriggerError::ensureLinked',
            'ensureUndefinedArrayKeyAbis',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $body,
                'HashTable::implement must not eagerly '.$forbidden.' (#35648)'
            );
        }
    }

    public function testReadStringKeyValueEnsuresUndefinedArrayKeyBeforeLookup(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $fnPos = strpos($source, 'private function implementReadStringKeyValue(');
        $this->assertNotFalse($fnPos);
        $nextFn = strpos($source, 'private function implementReadStringKeyHashtable(', $fnPos + 1);
        $this->assertNotFalse($nextFn);
        $chunk = substr($source, $fnPos, $nextFn - $fnPos);
        $this->assertStringContainsString(
            'ensureUndefinedArrayKeyAbis',
            $chunk,
            'implementReadStringKeyValue must ensureUndefinedArrayKeyAbis before lookup (#35648)'
        );
        $ensurePos = strpos($chunk, 'ensureUndefinedArrayKeyAbis');
        $lookupPos = strpos($chunk, '__compiler_undefined_array_key_warning_cstr');
        $this->assertNotFalse($ensurePos);
        $this->assertNotFalse($lookupPos);
        $this->assertLessThan($lookupPos, $ensurePos);
    }

    public function testHashTableReadLlvmStillEnsuresBeforeEmit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableReadLlvm.php');
        $this->assertStringContainsString('StringTriggerErrorJit::implement', $source);
    }

    public function testNoNewRuntimeCForLazyUndefinedArrayKeyAbis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'undefined_array_key.c',
            'compiler_undefined_array_key.c',
            'trigger_error.c',
        ] as $name) {
            $this->assertFileDoesNotExist(
                $runtimeDir.'/'.$name,
                'must not add '.$name.' for #35648 — PHP JIT bridges only'
            );
        }
        $linker = (string) file_get_contents(__DIR__.'/../../lib/AOT/Linker.php');
        $this->assertStringContainsString('RUNTIME_C_SOURCES = [', $linker);
        $this->assertStringNotContainsString('undefined_array_key.c', $linker);
    }
}
