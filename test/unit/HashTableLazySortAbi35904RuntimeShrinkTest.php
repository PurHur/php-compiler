<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * HashTable locale/natural/multisort LLVM is ensureSortAbi on first lookup (#35904).
 *
 * Thin hello-world must not NestedJIT strcoll/strnatcmp during HashTable init —
 * leftover NestedJIT vs Runtime ABI drift mints *.1 (#31894 / #32122).
 */
final class HashTableLazySortAbi35904RuntimeShrinkTest extends TestCase
{
    public function testRegisterDoesNotDeclareLazySortAbis(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $pos = strpos($source, 'public function register(): void');
        $this->assertNotFalse($pos);
        $next = strpos($source, 'private function registerFn', $pos);
        $this->assertNotFalse($next);
        $body = substr($source, $pos, $next - $pos);

        foreach ([
            'sortStringKeysLocale',
            'sortStringKeyValuesLocale',
            'sortPackedNatural',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $body,
                'HashTable::register must not declare '.$forbidden.' (#35904)'
            );
        }
        $this->assertStringNotContainsString("registerFn('__multisort__packed'", $body);
        $this->assertStringContainsString('ensureSortAbi', $body);
        $this->assertStringContainsString('sortStringKeyValuesNatural', $body);
    }

    public function testRuntimesEnsureSortAbiForUsedAbiOnly(): void
    {
        $key = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/KeySortRuntime.php');
        $this->assertStringContainsString('ensureSortAbi(self::ABI_KSORT_LOCALE)', $key);
        $ensurePos = strpos($key, 'public static function ensureLinked');
        $this->assertNotFalse($ensurePos);
        $ensureNext = strpos($key, 'private static function assertAbi', $ensurePos);
        $this->assertNotFalse($ensureNext);
        $ensureBody = substr($key, $ensurePos, $ensureNext - $ensurePos);
        $this->assertStringNotContainsString('ABI_KSORT_LOCALE', $ensureBody);

        $value = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ValueSortRuntime.php');
        $this->assertStringContainsString('ensureSortAbi(self::ABI_ASORT_LOCALE)', $value);

        $natural = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/NaturalSortRuntime.php');
        $this->assertStringContainsString('ensureSortAbi(self::ABI_STRKEY_NATURAL)', $natural);
        $this->assertStringContainsString('ensureSortAbi(self::ABI_STRKEY_NATURAL_CASE)', $natural);

        $multi = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MultisortRuntime.php');
        $this->assertStringContainsString('ensureMultisortPacked', $multi);
    }

    public function testNoNewRuntimeC(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach (['strcoll.c', 'strnatcmp.c', 'array_multisort.c'] as $name) {
            $this->assertFileDoesNotExist($runtimeDir.'/'.$name, 'must not add '.$name.' for #35904');
        }
    }
}
