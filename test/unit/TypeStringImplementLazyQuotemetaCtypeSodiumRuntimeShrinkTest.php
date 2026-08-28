<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type\String_::implement always-on StringQuotemeta / CtypeRuntime / StringSodium
 * ensureLinked (#35609 / peer #34513 Type::initialize lazy).
 *
 * Call sites link lazily so scripts that never touch those builtins skip NestedJIT on the
 * full load path (#32122 .1 mint class).
 */
final class TypeStringImplementLazyQuotemetaCtypeSodiumRuntimeShrinkTest extends TestCase
{
    public function testStringImplementDropsEagerQuotemetaCtypeSodiumEnsureLinked(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/String_.php');
        $this->assertStringContainsString('#35609', $source);
        $pos = strpos($source, 'public function implement(): void');
        $this->assertNotFalse($pos);
        $next = strpos($source, 'private function implementStrlen', $pos);
        $this->assertNotFalse($next);
        $body = substr($source, $pos, $next - $pos);

        foreach ([
            'StringQuotemeta::ensureLinked',
            'CtypeRuntime::ensureLinked',
            'StringSodium::ensureLinked',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $body,
                'Type\\String_::implement must not eagerly '.$forbidden.' (#35609)'
            );
        }
        $this->assertStringContainsString(
            'LOAD_TYPE_STANDALONE === $this->context->loadType',
            $body,
            'standalone still defers String_::implement early (#13571)'
        );
    }

    public function testCallSitesEnsureLinkBeforeLookup(): void
    {
        $checks = [
            'ext/standard/quotemeta.php' => 'StringQuotemeta::ensureLinked',
            'ext/ctype/JitCtype.php' => 'CtypeRuntime::ensureLinked',
            'ext/sodium/JitSodium.php' => 'StringSodium::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $file = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $file, $rel.' must link before use (#35609)');
        }
    }

    public function testNoNewRuntimeCForLazyQuotemetaCtypeSodiumAbis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'quotemeta_runtime.c',
            'ctype_runtime.c',
            'sodium_runtime.c',
        ] as $name) {
            $this->assertFileDoesNotExist(
                $runtimeDir.'/'.$name,
                'must not add '.$name.' for #35609 — PHP JIT bridges only'
            );
        }
        $linker = (string) file_get_contents(__DIR__.'/../../lib/AOT/Linker.php');
        $this->assertStringContainsString('RUNTIME_C_SOURCES = [', $linker);
        $this->assertStringContainsString('];', $linker);
        $this->assertStringNotContainsString('quotemeta', $linker);
        $this->assertStringNotContainsString('ctype_runtime', $linker);
    }
}
