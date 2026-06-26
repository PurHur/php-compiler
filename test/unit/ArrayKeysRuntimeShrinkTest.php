<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayKeysJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_keys() JIT routes through ArrayKeysJitHelper PHP not ArrayBuiltinHelper LLVM (#12340). */
final class ArrayKeysRuntimeShrinkTest extends TestCase
{
    public function testArrayKeysRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayKeysRuntime.php');
        $this->assertStringContainsString('ArrayKeysJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::buildKeysArrayFromVariable', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::buildKeysArrayFiltered', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_keys.php');
        $this->assertStringContainsString('ArrayKeysRuntime::keys', $builtin);
        $this->assertStringContainsString('ArrayKeysRuntime::keysFiltered', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildKeysArray', $builtin);
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
