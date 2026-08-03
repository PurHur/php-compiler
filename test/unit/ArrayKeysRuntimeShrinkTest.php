<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayKeysJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_keys() JIT: call-site HashTableKeysLlvm for 1-arg; NestedJIT for filtered (#12340, #27211). */
final class ArrayKeysRuntimeShrinkTest extends TestCase
{
    public function testArrayKeysRuntimeUsesCallSiteLlvmNotNestedJitHelper(): void
    {
        // #27211: NestedJIT of ArrayKeysJitHelper returned empty under thin AOT; call-site HashTableKeysLlvm.
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayKeysRuntime.php');
        $this->assertStringContainsString('HashTableKeysLlvm', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::loadHashTable', $runtime);
        $this->assertStringNotContainsString('buildKeysArrayFromVariable', $runtime);
        $this->assertStringNotContainsString('buildKeysArrayFiltered', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);
        $this->assertStringNotContainsString('ArrayKeysJitHelper::keysCopy', $runtime);

        $keysCopy = (string) file_get_contents(__DIR__.'/../../lib/JIT/Call/HashTableKeysCopy.php');
        $this->assertStringContainsString('HashTableKeysLlvm::keys', $keysCopy);
        $this->assertStringNotContainsString('ArrayKeysRuntime::keys', $keysCopy);
        $this->assertStringNotContainsString('buildKeysArrayFromVariable', $keysCopy);

        $keysMatching = (string) file_get_contents(__DIR__.'/../../lib/JIT/Call/HashTableKeysMatchingCopy.php');
        $this->assertStringContainsString('ArrayKeysRuntime::keysFiltered', $keysMatching);
        $this->assertStringNotContainsString('buildKeysArrayFiltered', $keysMatching);

        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function buildKeysArrayFromVariable', $arrayBuiltin);
        $this->assertStringNotContainsString('function buildKeysArrayFiltered', $arrayBuiltin);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_keys.php');
        $this->assertStringContainsString('ArrayKeysRuntime::keys', $builtin);
        $this->assertStringContainsString('ArrayKeysRuntime::keysFiltered', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildKeysArray', $builtin);

        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableKeysLlvm.php');
        $this->assertStringContainsString('function keys', $llvm);
        $this->assertStringContainsString('keysFromPairs', $llvm);

        $nested = (string) file_get_contents(__DIR__.'/../../lib/JIT/NestedVmHashTableMethodLlvm.php');
        $this->assertStringContainsString("'keyscopy'", $nested);
        $this->assertStringNotContainsString('isThinStandaloneAotMain()', $nested);
    }

    public function testArrayKeysJitHelperMatchesHashTableKeysCopy(): void
    {
        $src = self::mapTable(['a' => 10, 'b' => 20]);
        $keys = ArrayKeysJitHelper::keysCopy($src);
        $out = [];
        foreach ($keys->iterateKeyed(true) as [, $keyVar]) {
            $out[] = $keyVar->resolveIndirect()->toString();
        }
        $this->assertSame(['a', 'b'], $out);
    }

    public function testArrayKeysJitHelperMatchesHashTableKeysMatchingCopy(): void
    {
        $src = self::mapTable(['a' => 10, 'b' => 20, 'c' => 10]);
        $search = new Variable(Variable::TYPE_INTEGER);
        $search->int(10);
        $keys = ArrayKeysJitHelper::keysMatching($src, $search, false);
        $out = [];
        foreach ($keys->iterateKeyed(true) as [, $keyVar]) {
            $out[] = $keyVar->resolveIndirect()->toString();
        }
        $this->assertSame(['a', 'c'], $out);
    }

    /** @param array<string, int> $pairs */
    private static function mapTable(array $pairs): HashTable
    {
        $ht = new HashTable();
        foreach ($pairs as $key => $value) {
            $var = new Variable(Variable::TYPE_INTEGER);
            $var->int($value);
            $ht->add($key, $var);
        }

        return $ht;
    }
}
