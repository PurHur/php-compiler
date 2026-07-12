<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayPopJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_pop() JIT routes through ArrayPopJitHelper PHP not ArrayBuiltinHelper LLVM (#12647, #14317). */
final class ArrayPopRuntimeShrinkTest extends TestCase
{
    public function testArrayPopRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayPopRuntime.php');
        $this->assertStringContainsString('ArrayPopJitHelper', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::popLast', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function popLast', $helper);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_pop.php');
        $this->assertStringContainsString('ArrayPopRuntime::pop', $builtin);
    }

    public function testArrayPopJitHelperPopsLastElement(): void
    {
        $ht = self::listTable(1, 2, 'tail');
        $out = ArrayPopJitHelper::pop($ht);
        $this->assertSame(Variable::TYPE_STRING, $out->type);
        $this->assertSame('tail', $out->toString());
        $this->assertSame(2, $ht->getNumElements());
    }

    public function testArrayPopJitHelperEmptyReturnsNull(): void
    {
        $ht = new HashTable();
        $out = ArrayPopJitHelper::pop($ht);
        $this->assertSame(Variable::TYPE_NULL, $out->type);
    }

    /** @param list<int|string> $values */
    private static function listTable(int|string ...$values): HashTable
    {
        $ht = new HashTable();
        foreach ($values as $value) {
            $var = new Variable();
            if (\is_int($value)) {
                $var->int($value);
            } else {
                $var->string($value);
            }
            $ht->append($var);
        }

        return $ht;
    }
}
