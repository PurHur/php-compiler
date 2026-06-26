<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayMapJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_map() JIT routes null/string-builtin through ArrayMapJitHelper PHP not ArrayBuiltinHelper LLVM (#10183). */
final class ArrayMapRuntimeShrinkTest extends TestCase
{
    public function testArrayMapRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayMapRuntime.php');
        $this->assertStringContainsString('ArrayMapJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::buildMapArray', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_map.php');
        $this->assertStringContainsString('ArrayMapRuntime::mapSingle', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildMapArray', $builtin);
    }

    public function testArrayMapJitHelperMatchesVmSemantics(): void
    {
        $src = self::listTable(1, 2, 3);
        $identity = ArrayMapJitHelper::mapNullIdentity($src);
        $keys = [];
        foreach ($identity->iterateKeyed(true) as [$key, $value]) {
            $keys[] = $key->resolveIndirect()->toInt();
            $this->assertSame(Variable::TYPE_INTEGER, $value->resolveIndirect()->type);
        }
        $this->assertSame([0, 1, 2], $keys);

        $mapped = ArrayMapJitHelper::mapWithBuiltin($src, 'strval');
        $out = [];
        foreach ($mapped->iterateKeyed(true) as [$key, $value]) {
            $out[$key->resolveIndirect()->toInt()] = $value->resolveIndirect()->toString();
        }
        $this->assertSame(['1', '2', '3'], array_values($out));
    }

    /** @param list<int> $values */
    private static function listTable(int ...$values): HashTable
    {
        $ht = new HashTable();
        foreach ($values as $value) {
            $var = new Variable(Variable::TYPE_INTEGER);
            $var->int($value);
            $ht->append($var);
        }

        return $ht;
    }
}
