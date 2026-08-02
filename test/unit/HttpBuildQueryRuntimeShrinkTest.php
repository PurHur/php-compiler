<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;
use PHPCompiler\ext\standard\HttpBuildQueryJitHelper;
use PHPCompiler\ext\standard\VmHttpBuildQuery;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** StringHttpBuildQuery via HttpBuildQueryJitHelper + JitVmHelperLink::ensureCompiled (#9443, #24887). */
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

    public function testStringHttpBuildQueryUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHttpBuildQuery.php');
        $this->assertStringContainsString('HttpBuildQueryJitHelper::build', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $source);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
    }

    public function testHttpBuildQueryJitHelperIsNestedJitSafeInline(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/HttpBuildQueryJitHelper.php');
        $this->assertStringContainsString('percentEncode', $source);
        $this->assertStringContainsString('iterateKeyed', $source);
        $this->assertStringContainsString('exportKeyValuePairs', $source);
        $this->assertStringNotContainsString('VmHttpBuildQuery::buildFromHashTable', $source);
        $this->assertStringNotContainsString('as [$', $source);
        $this->assertStringNotContainsString('rawurlencode(', $source);
        $this->assertStringNotContainsString('urlencode(', $source);
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

    public function testJitHttpBuildQueryEnsuresLinkedAtCallSite(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitHttpBuildQuery.php');
        $this->assertStringContainsString('StringHttpBuildQuery::ensureLinked', $jit);
        $call = (string) file_get_contents(__DIR__.'/../../ext/standard/http_build_query.php');
        $this->assertStringContainsString('StringHttpBuildQuery::ensureLinked', $call);
    }
}
