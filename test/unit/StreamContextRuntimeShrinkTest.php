<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamContextJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** StreamContextRuntime must route through StreamContextJitHelper PHP, not LLVM hashtable walker (#9340). */
final class StreamContextRuntimeShrinkTest extends TestCase
{
    public function testStreamContextRuntimeUsesJitHelperNotLlvmWalker(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamContextRuntime.php');
        $this->assertStringContainsString('StreamContextJitHelper', $source);
        $this->assertStringNotContainsString('implementMergeOptions', $source);
        $this->assertStringNotContainsString('mergeScalar', $source);
        $this->assertStringNotContainsString("GLOBAL_DEFAULT = 'phpc_stream_context_default'", $source);
        $this->assertStringNotContainsString("GLOBAL_NEXT_ID = 'phpc_stream_context_next_id'", $source);
        $this->assertStringNotContainsString('__hashtable__setStringKeyLong', $source);
        $this->assertLessThan(280, \substr_count($source, "\n") + 1);
    }

    public function testJitStreamContextGetDefaultUsesHelperForEmbed(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamContextGetDefault.php');
        $this->assertStringContainsString('StreamContextJitHelper::getDefault', $source);
        $this->assertStringContainsString('invokeStandalone', $source);
        $this->assertStringContainsString('invokeEmbed', $source);
    }

    public function testStreamContextJitHelperUsesHashTableNativePath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StreamContextJitHelper.php');
        $this->assertStringContainsString('VmParseStr::mergeInto', $source);
        $this->assertStringContainsString('VmStreamContext::MARKER_KEY', $source);
        $this->assertStringNotContainsString('createFromVmOptions', $source);
        $this->assertStringNotContainsString('VmHttpBuildQuery::export', $source);
    }

    public function testStreamContextJitHelperDefaultRoundTrip(): void
    {
        $options = new HashTable();
        $http = new HashTable();
        $timeout = new Variable();
        $timeout->int(3);
        $http->add('timeout', $timeout);
        $httpWrap = new Variable();
        $httpWrap->array($http);
        $options->add('http', $httpWrap);

        StreamContextJitHelper::setDefault($options);
        $ctx = StreamContextJitHelper::getDefault(null);
        $timeoutOut = $ctx->find('http')->resolveIndirect()->toArray()->find('timeout');
        $this->assertSame(3, $timeoutOut->resolveIndirect()->toInt());
    }
}
