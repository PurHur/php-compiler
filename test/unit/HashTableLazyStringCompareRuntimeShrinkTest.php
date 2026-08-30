<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop HashTable::implement always-on NestedJIT for locale/natural string compare ABIs
 * (#35626 / peer #35614 Type::String_::implement lazy batch).
 *
 * Thin hello-world AOT must not NestedJIT strcoll/strnatcmp during init — leftover
 * HashTable NestedJIT vs Runtime ABI drift mints *.1 (#31894 / #32122).
 */
final class HashTableLazyStringCompareRuntimeShrinkTest extends TestCase
{
    public function testHashTableImplementDropsEagerStringCompareEnsureLinked(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString('#35626', $source);
        $pos = strpos($source, 'public function implement(): void');
        $this->assertNotFalse($pos);
        $next = strpos($source, 'public function ensureMultisortPacked', $pos);
        $this->assertNotFalse($next);
        $body = substr($source, $pos, $next - $pos);

        foreach ([
            '$this->ensureStringCompareAbis',
            '$this->ensureStrcollAbis',
            '$this->ensureNaturalCompareAbis',
            'StringNaturalCompare::ensureStandaloneBodies',
            'StringStrcoll::ensureLinked',
            'implementSortStringKeysLocale',
            'implementSortStringKeyValuesLocale',
            'implementSortPackedNatural',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $body,
                'HashTable::implement must not eagerly '.$forbidden.' (#35626 / #35904)'
            );
        }
    }

    public function testLocaleSortImplementMethodsEnsureStrcollBeforeLookup(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        foreach ([
            'implementSortStringKeysLocale',
            'implementSortStringKeyValuesLocale',
        ] as $method) {
            $fnPos = strpos($source, "private function {$method}(");
            $this->assertNotFalse($fnPos, $method.' must exist');
            $nextFn = strpos($source, 'private function ', $fnPos + 1);
            $chunk = false === $nextFn ? substr($source, $fnPos) : substr($source, $fnPos, $nextFn - $fnPos);
            $this->assertStringContainsString(
                'ensureStrcollAbis',
                $chunk,
                $method.' must ensureStrcollAbis before lookup (#35626)'
            );
            $this->assertStringContainsString(
                'StringStrcoll::ABI_STRCOLL',
                $chunk,
                $method.' must lookup strcoll ABI (#35626)'
            );
        }
    }

    public function testNaturalSortImplementMethodsEnsureNaturalCompareBeforeLookup(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        foreach ([
            'implementSortStringKeyValuesNatural',
            'implementSortStringKeyValuesNaturalCase',
            'implementSortPackedNatural',
        ] as $method) {
            $fnPos = strpos($source, "private function {$method}(");
            $this->assertNotFalse($fnPos, $method.' must exist');
            $nextFn = strpos($source, 'private function ', $fnPos + 1);
            $chunk = false === $nextFn ? substr($source, $fnPos) : substr($source, $fnPos, $nextFn - $fnPos);
            $this->assertStringContainsString(
                'ensureNaturalCompareAbis',
                $chunk,
                $method.' must ensureNaturalCompareAbis before lookup (#35626)'
            );
        }
    }

    public function testNoNewRuntimeCForLazyStringCompareAbis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'strcoll.c',
            'strnatcmp.c',
            'strnatcasecmp.c',
        ] as $name) {
            $this->assertFileDoesNotExist(
                $runtimeDir.'/'.$name,
                'must not add '.$name.' for #35626 — PHP JIT bridges only'
            );
        }
        $linker = (string) file_get_contents(__DIR__.'/../../lib/AOT/Linker.php');
        $this->assertStringContainsString('RUNTIME_C_SOURCES = [', $linker);
        $this->assertStringNotContainsString('strcoll.c', $linker);
    }
}
