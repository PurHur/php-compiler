<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayUniqueJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPUnit\Framework\TestCase;

/**
 * array_unique() AOT/JIT uses ArrayUniqueLlvm (#27066); VM SSOT remains ArrayUniqueJitHelper (#12341).
 */
final class ArrayUniqueRuntimeShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 5800;

    public function testArrayUniqueRuntimeUsesCallSiteLlvmNotNestedJitBridge(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayUniqueRuntime.php');
        $this->assertStringContainsString('ArrayUniqueLlvm', $runtime);
        $this->assertStringContainsString('nativeListToHashTable', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayUnique', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_unique.php');
        $this->assertStringContainsString('ArrayUniqueRuntime::unique', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayUnique', $builtin);

        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayUniqueLlvm.php');
        $this->assertStringContainsString('uniqueHashTable', $llvm);
    }

    public function testArrayBuiltinHelperLineBudgetAfterNativeUniqueLlvmDeletion(): void
    {
        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function arrayUnique(', $arrayBuiltin);
        $this->assertStringNotContainsString('function arrayUniqueHashTable(', $arrayBuiltin);
        $this->assertStringNotContainsString('function destContainsPackedEntry(', $arrayBuiltin);
        $this->assertStringNotContainsString('function valueEntryToNumericDouble(', $arrayBuiltin);

        $lines = substr_count($arrayBuiltin, "\n") + 1;
        $this->assertLessThanOrEqual(
            self::ARRAY_BUILTIN_HELPER_MAX_LINES,
            $lines,
            'ArrayBuiltinHelper.php LOC after dead array_unique native LLVM deletion (#18221)'
        );
    }

    public function testArrayUniqueJitHelperDedupesSortString(): void
    {
        $ht = self::listTable('a', 'b', 'a');
        $out = ArrayUniqueJitHelper::unique($ht, StdlibConstants::SORT_STRING);
        $this->assertSame(2, self::countElements($out));
    }

    public function testArrayUniqueJitHelperSortRegularScalarEquivalence(): void
    {
        $ht = new HashTable();
        foreach ([1, '1', 1.0] as $i => $raw) {
            $var = new Variable();
            if (\is_int($raw)) {
                $var->int($raw);
            } elseif (\is_float($raw)) {
                $var->float($raw);
            } else {
                $var->string((string) $raw);
            }
            $ht->addIndex($i, $var);
        }
        $out = ArrayUniqueJitHelper::unique($ht, StdlibConstants::SORT_REGULAR);
        $this->assertSame(1, self::countElements($out));
    }

    public function testArrayUniqueJitHelperRejectsPlainObjectUnderSortString(): void
    {
        $ht = new HashTable();
        $obj = new Variable();
        $entry = new \PHPCompiler\VM\ObjectEntry(new \PHPCompiler\VM\ClassEntry('stdClass'));
        $obj->object($entry);
        $ht->append($obj);
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Object of class stdClass could not be converted to string');
        ArrayUniqueJitHelper::unique($ht, StdlibConstants::SORT_STRING);
    }

    public function testArrayUniqueJitHelperHasNoCastObjectToStringOrPregMatch(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/standard/ArrayUniqueJitHelper.php');
        $this->assertStringNotContainsString(
            '->castObjectToString(',
            $src,
            'NestedJIT rebinds VM::castObjectToString onto the helper (#27066)'
        );
        $this->assertStringNotContainsString(
            'preg_match(',
            $src,
            'NestedJIT preg_match needs __compiler_preg_match_ex at AOT link (#27066)'
        );
    }

    private static function countElements(HashTable $ht): int
    {
        $n = 0;
        foreach ($ht->iterateKeyed(true) as $_) {
            ++$n;
        }

        return $n;
    }

    /** @param list<string> $values */
    private static function listTable(string ...$values): HashTable
    {
        $ht = new HashTable();
        foreach ($values as $value) {
            $var = new Variable(Variable::TYPE_STRING);
            $var->string($value);
            $ht->append($var);
        }

        return $ht;
    }
}
