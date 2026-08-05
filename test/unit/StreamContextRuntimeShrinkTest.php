<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamContextJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * StreamContext NestedJIT via JitVmHelperLink (#9340, #12895, #19817, #23049).
 */
final class StreamContextRuntimeShrinkTest extends TestCase
{
    public function testBuiltinStreamContextRuntimeIsThinOrchestrator(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamContextKernel.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamContextThinAot.php');
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/StreamContextRuntime.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamContextStandaloneLlvm.php');

        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamContextRuntime.php');
        $this->assertStringContainsString('JitStreamContextKernel', $orchestrator);
        $this->assertStringContainsString('JitStreamContextKernel::ensureLinked', $orchestrator);
        $this->assertStringContainsString('JitStreamContextKernel::helperFunction', $orchestrator);
        $this->assertStringNotContainsString('NestedJitCompileScope', $orchestrator);
        $this->assertStringNotContainsString('__phpc_stream_context_create', $orchestrator);
        $this->assertStringNotContainsString('implementMergeOptions', $orchestrator);
        $this->assertLessThan(45, \substr_count($orchestrator, "\n") + 1);
    }

    public function testKernelUsesThinAotForUserScriptAndJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamContextKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitStreamContextKernel', $source);
        $this->assertStringContainsString('__phpc_stream_context_create', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('StreamContextJitHelper', $source);
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('JitStreamContextThinAot', $source);
        $this->assertStringContainsString('JitNestedHelperCoerce', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('dirname(__DIR__, 2)', $source);
        $this->assertLessThan(340, \substr_count($source, "\n") + 1);
    }

    public function testSpineBundleIncludesKernelThinAotAndOrchestrator(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitStreamContextKernel.php', $spine);
        $this->assertStringContainsString('JitStreamContextThinAot.php', $spine);
        $this->assertStringContainsString('StreamContextRuntime.php', $spine);
        $kernelPos = strpos($spine, 'JitStreamContextKernel.php');
        $thinPos = strpos($spine, 'JitStreamContextThinAot.php');
        $orchPos = strpos($spine, 'lib/JIT/Builtin/StreamContextRuntime.php');
        $this->assertNotFalse($kernelPos);
        $this->assertNotFalse($thinPos);
        $this->assertNotFalse($orchPos);
        $this->assertLessThan($thinPos, $kernelPos, 'kernel must load before thin AOT');
        $this->assertLessThan($orchPos, $thinPos, 'thin AOT must load before thin orchestrator');
    }

    public function testJitStreamContextGetDefaultUsesHelperForEmbed(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamContextGetDefault.php');
        $this->assertStringContainsString('StreamContextJitHelper::getDefault', $source);
        $this->assertStringNotContainsString('invokeStandalone', $source);
        $this->assertStringNotContainsString('phpc_stream_context_default', $source);
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
