<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop HashTable::implement always-on __multisort__packed LLVM (#35904).
 *
 * Locale/natural sorts stay at type init — lazy bodies during {main} pollute IR
 * (Module.php:180 memcpy_done / cross-function SSA). array_multisort() call sites
 * ensureMultisortPacked before lookup. No new runtime C.
 */
final class HashTableLazyLocaleNaturalMultisortRuntimeShrinkTest extends TestCase
{
    public function testHashTableImplementDropsEagerMultisort(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString('#35904', $source);
        $pos = strpos($source, 'public function implement(): void');
        $this->assertNotFalse($pos);
        $next = strpos($source, 'public function ensureMultisortPacked', $pos);
        $this->assertNotFalse($next);
        $body = substr($source, $pos, $next - $pos);
        $this->assertStringNotContainsString(
            'implementMultisortPacked()',
            $body,
            'HashTable::implement must not eagerly implementMultisortPacked (#35904)'
        );
        $this->assertStringContainsString('implementSortStringKeysLocale', $body);
        $this->assertStringContainsString('implementSortPackedNatural', $body);
    }

    public function testRegisterDropsEagerMultisortDecl(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $pos = strpos($source, 'public function register(): void');
        $this->assertNotFalse($pos);
        $next = strpos($source, 'private function registerFn', $pos);
        $this->assertNotFalse($next);
        $body = substr($source, $pos, $next - $pos);
        $this->assertStringNotContainsString(
            "registerFn('__multisort__packed'",
            $body,
            'HashTable::register must not eagerly declare __multisort__packed (#35904)'
        );
    }

    public function testMultisortRuntimeEnsuresBeforeLookup(): void
    {
        $multi = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MultisortRuntime.php');
        $this->assertStringContainsString('hashtable->ensureMultisortPacked()', $multi);
    }

    public function testNoNewRuntimeCForLazyMultisort(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist($runtimeDir.'/multisort.c');
        $this->assertFileDoesNotExist($runtimeDir.'/phpc_multisort.c');
    }
}
