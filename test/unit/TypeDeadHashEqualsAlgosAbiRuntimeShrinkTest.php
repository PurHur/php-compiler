<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on hash_equals / hash_*_algos ABI shells from Builtin\Type (#32875).
 *
 * NestedJIT/AOT bridges stay StringHashEquals / StringHashHmacAlgos / StringHashAlgos.
 * Runtime owners declare module-locally via JitVmHelperLink::ensureBridge so leftover
 * Type empty decls cannot mint hash_equals.1 (#31894 / #32122).
 */
final class TypeDeadHashEqualsAlgosAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_hash_equals',
            '__compiler_hash_hmac_algos',
            '__compiler_hash_algos',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnHashEqualsAlgosAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32875', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32875)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32875)"
            );
        }
        $this->assertStringContainsString('LibcExtern::ensureExitAbort', $type);
        $this->assertStringContainsString('StringHashEquals::ensureLinked', $type);
        $this->assertStringContainsString('StringHashHmacAlgos::ensureLinked', $type);
        $this->assertStringContainsString('StringHashAlgos::ensureLinked', $type);
    }

    public function testRuntimeOwnersDeclareHashEqualsAlgosAbisModuleLocally(): void
    {
        foreach ([
            'StringHashEquals.php' => '#32875',
            'StringHashHmacAlgos.php' => '#32875',
            'StringHashAlgos.php' => '#32875',
        ] as $file => $tag) {
            $svc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/'.$file);
            $this->assertStringContainsString($tag, $svc);
            $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $svc);
            $this->assertStringContainsString('getNamedFunction', $svc);
        }
        foreach ($this->droppedAbis() as $sym) {
            $hits = 0;
            foreach (['StringHashEquals.php', 'StringHashHmacAlgos.php', 'StringHashAlgos.php'] as $file) {
                $svc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/'.$file);
                if (str_contains($svc, $sym)) {
                    ++$hits;
                }
            }
            $this->assertGreaterThan(0, $hits, "{$sym} must remain owned by a Runtime (#32875)");
        }
    }

    public function testTypeInitializeStillEnsureLinksHashEqualsAlgosRuntimes(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringHashEquals::ensureLinked($this->context)', $type);
        $this->assertStringContainsString('StringHashHmacAlgos::ensureLinked($this->context)', $type);
        $this->assertStringContainsString('StringHashAlgos::ensureLinked($this->context)', $type);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/HashEqualsJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/hash/HashAlgosJitHelper.php');
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/hash_equals.c'
        );
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/runtime/hash_equals.c'
        );
    }
}
