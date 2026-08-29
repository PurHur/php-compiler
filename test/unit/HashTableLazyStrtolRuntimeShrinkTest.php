<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop HashTable::implement always-on LibcExtern::ensureStrtolDecl (#35751 / peer #35626).
 *
 * Numeric-string key lookup calls ensureLibcStrtol before lookupFunction so hello-world
 * AOT skips the strtol decl during HashTable init (#31894 / #32122 .1 mint class).
 */
final class HashTableLazyStrtolRuntimeShrinkTest extends TestCase
{
    public function testHashTableImplementDropsEagerStrtolEnsure(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString('#35751', $source);
        $pos = strpos($source, 'public function implement(): void');
        $this->assertNotFalse($pos);
        $next = strpos($source, 'private function ensureLibcStrtol', $pos);
        $this->assertNotFalse($next);
        $body = substr($source, $pos, $next - $pos);
        $this->assertStringNotContainsString(
            '$this->ensureLibcStrtol()',
            $body,
            'HashTable::implement must not eagerly ensureLibcStrtol (#35751)'
        );
    }

    public function testNumericStringKeyLookupEnsuresStrtolBeforeLookup(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $fnPos = strpos($source, 'private function tryLookupPackedIntFromStringKey(');
        $this->assertNotFalse($fnPos);
        $nextFn = strpos($source, 'private function stringIsIntegerNumericKey(', $fnPos);
        $this->assertNotFalse($nextFn);
        $chunk = substr($source, $fnPos, $nextFn - $fnPos);
        $this->assertStringContainsString(
            'ensureLibcStrtol',
            $chunk,
            'tryLookupPackedIntFromStringKey must ensureLibcStrtol before lookup (#35751)'
        );
        $this->assertStringContainsString(
            "lookupFunction('strtol')",
            $chunk
        );
        $ensurePos = strpos($chunk, 'ensureLibcStrtol');
        $lookupPos = strpos($chunk, "lookupFunction('strtol')");
        $this->assertNotFalse($ensurePos);
        $this->assertNotFalse($lookupPos);
        $this->assertLessThan($lookupPos, $ensurePos);
    }

    public function testNoNewRuntimeCForLazyStrtolAbi(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'strtol_runtime.c',
            'phpc_strtol.c',
        ] as $name) {
            $this->assertFileDoesNotExist(
                $runtimeDir.'/'.$name,
                'must not add '.$name.' for #35751 — LibcExtern::ensureStrtolDecl only'
            );
        }
    }
}
