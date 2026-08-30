<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Refcount::implement always-on WeakRef/GC NestedJIT (#35802 / peer #35751).
 *
 * Thin hello-world AOT must not NestedJIT weakref/gc ABIs during Refcount init —
 * leftover NestedJIT vs Runtime ABI drift mints phpc_weakref_*.1 / phpc_gc_*.1
 * (#31894 / #32122).
 */
final class RefcountLazyWeakRefRuntimeShrinkTest extends TestCase
{
    public function testRefcountImplementDropsEagerWeakRefGcEnsureLinked(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Refcount.php');
        $this->assertStringContainsString('#35802', $source);
        $pos = strpos($source, 'public function implement(): void');
        $this->assertNotFalse($pos);
        $next = strpos($source, 'private function ensureDelrefHelperAbis', $pos);
        $this->assertNotFalse($next);
        $body = substr($source, $pos, $next - $pos);
        foreach ([
            'WeakRefRuntime::ensureLinked',
            'WeakRefNative::registerDeclarations',
            'GcCollectCyclesRuntime::ensureDeclarations',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $body,
                'Refcount::implement must not eagerly '.$forbidden.' (#35802)'
            );
        }
    }

    public function testImplementDelrefEnsuresWeakRefGcBeforeLookup(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Refcount.php');
        $fnPos = strpos($source, 'private function implementDelref(): void');
        $this->assertNotFalse($fnPos);
        $nextFn = strpos($source, 'private function implementSeparate(): void', $fnPos);
        $this->assertNotFalse($nextFn);
        $chunk = substr($source, $fnPos, $nextFn - $fnPos);
        $this->assertStringContainsString(
            'ensureDelrefHelperAbis',
            $chunk,
            'implementDelref must ensureDelrefHelperAbis before lookup (#35802)'
        );
        $ensurePos = strpos($chunk, 'ensureDelrefHelperAbis');
        $lookupPos = strpos($chunk, "lookupFunction('phpc_weakref_clear_object_typed')");
        $this->assertNotFalse($ensurePos);
        $this->assertNotFalse($lookupPos);
        $this->assertLessThan($lookupPos, $ensurePos);
    }

    public function testRefcountPreMatchesLazyDelrefPattern(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Refcount.pre');
        $this->assertStringContainsString('#35802', $source);
        $this->assertStringContainsString('ensureDelrefHelperAbis', $source);
        $pos = strpos($source, 'public function implement(): void');
        $this->assertNotFalse($pos);
        $next = strpos($source, 'private function ensureDelrefHelperAbis', $pos);
        $this->assertNotFalse($next);
        $body = substr($source, $pos, $next - $pos);
        $this->assertStringNotContainsString('WeakRefRuntime::ensureLinked', $body);
    }

    public function testNoNewRuntimeCForLazyWeakRefAbis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'weakref.c',
            'phpc_weakref.c',
            'gc_unregister.c',
        ] as $name) {
            $this->assertFileDoesNotExist(
                $runtimeDir.'/'.$name,
                'must not add '.$name.' for #35802 — PHP JIT bridges only'
            );
        }
    }
}
