<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;
use PHPCompiler\ext\standard\HttpBuildQueryJitHelper;
use PHPCompiler\ext\standard\VmHttpBuildQuery;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** StringHttpBuildQuery must route through HttpBuildQueryJitHelper PHP, not LLVM walker (#9443). */
final class HttpBuildQueryRuntimeShrinkTest extends TestCase
{
    public function testStringHttpBuildQueryUsesJitHelperNotLlvmWalker(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHttpBuildQuery.php');
        $this->assertStringContainsString('HttpBuildQueryJitHelper', $source);
        $this->assertStringNotContainsString('hbq_entry', $source);
        $this->assertStringNotContainsString('StringHttpBuildQueryStandaloneLlvm', $source);
        $this->assertStringNotContainsString('__hashtable__setStringKeyString', $source);
        $this->assertStringNotContainsString('strkey_node', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringHttpBuildQueryStandaloneLlvm.php');
    }

    public function testHttpBuildQueryJitHelperDelegatesToVmHttpBuildQuery(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/HttpBuildQueryJitHelper.php');
        $this->assertStringContainsString('VmHttpBuildQuery::build', $source);
    }

    public function testHttpBuildQueryJitHelperMatchesVmHttpBuildQuery(): void
    {
        $ht = new HashTable();
        $a = new Variable();
        $a->int(1);
        $ht->add('a', $a);
        $nested = new HashTable();
        $c = new Variable();
        $c->int(2);
        $nested->add('c', $c);
        $b = new Variable();
        $b->array($nested);
        $ht->add('b', $b);

        $expected = VmHttpBuildQuery::build(['a' => 1, 'b' => ['c' => 2]]);
        $actual = HttpBuildQueryJitHelper::build($ht, '', '&', VmHttpBuildQuery::ENCODING_RFC1738);
        $this->assertSame($expected, $actual);
    }
}
