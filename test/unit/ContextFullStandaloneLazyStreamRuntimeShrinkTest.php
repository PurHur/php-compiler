<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureFullStandaloneBodies always-on NestedJIT of StreamLifecycle /
 * StreamRead / StreamFilter / StreamBucket / FunctionStatic (#35086 / peers #34836 / #35073).
 *
 * Full standalone must not NestedJIT feof/fclose/stream_bucket_/phpc_fn_static_ during
 * init (#32122 .1 mint class). Call sites already ensureLinked before lookup.
 */
final class ContextFullStandaloneLazyStreamRuntimeShrinkTest extends TestCase
{
    public function testEnsureFullDropsEagerStreamAndFunctionStaticNestedJit(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#35086', $context);
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullEnd = strpos($context, 'public function compileToFile', $fullPos);
        $this->assertNotFalse($fullEnd);
        $fullBody = substr($context, $fullPos, $fullEnd - $fullPos);

        foreach ([
            'StreamLifecycleRuntime::ensureLinked($this)',
            'StreamReadRuntime::ensureLinked($this)',
            'StreamFilter::ensureLinked($this)',
            'JitStreamBucketKernel::ensureStandaloneBodies($this)',
            'FunctionStaticRuntime::ensureStandaloneBodies($this)',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $fullBody,
                'ensureFullStandaloneBodies must not eagerly '.$forbidden.' (#35086)'
            );
        }

        // ValueEcho deferred to call sites (#35143); CliArgv/#35133 + SuperglobalRefresh/#35137 at compileToFile.
        $this->assertStringNotContainsString(
            'ValueEchoRuntime::ensureLinked($this)',
            $fullBody,
            'ValueEcho deferred to emitValue/helpers (#35143)'
        );
        $this->assertStringNotContainsString(
            'SuperglobalRefreshRuntime::ensureStandaloneBodies($this)',
            $fullBody,
            'SuperglobalRefresh deferred to compileToFile (#35137)'
        );
    }

    public function testCallSitesStillEnsureBeforeLookup(): void
    {
        $checks = [
            'ext/standard/JitFclose.php' => 'StreamLifecycleRuntime::ensureLinkedForUserScriptLowering',
            'ext/standard/JitFeof.php' => 'StreamLifecycleRuntime::ensureLinkedForUserScriptLowering',
            'ext/standard/JitFgetc.php' => 'StreamReadRuntime::ensureLinked',
            'ext/standard/JitFgets.php' => 'StreamReadRuntime::ensureLinked',
            'ext/standard/JitStreamBucket.php' => 'StreamBucket::ensureLinked',
            'ext/standard/JitIsResource.php' => 'StreamBucket::ensureLinked',
            'lib/JIT/Builtin/StringVarDump.php' => 'StreamLifecycleRuntime::ensureLinked',
            'lib/JIT/Builtin/StringPrintR.php' => 'StreamLifecycleRuntime::ensureLinked',
            'lib/JIT/Builtin/SilenceRuntime.php' => 'StreamLifecycleRuntime::ensureLinked',
            'lib/JIT/Builtin/StreamReadJit.php' => 'StreamFilter::ensureLinked',
            'lib/JIT/Builtin/StreamIoJit.php' => 'StreamFilter::ensureLinked',
            'lib/JIT/FunctionStaticHelper.php' => 'FunctionStaticRuntime::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must ensure lazily (#35086)');
        }
    }

    public function testNoNewRuntimeCForFullStreamLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/stream_lifecycle.c',
            'must not add stream_lifecycle.c for #35086 — PHP JIT bridges only'
        );
        $this->assertFileDoesNotExist(
            $runtimeDir.'/stream_bucket.c',
            'must not add stream_bucket.c for #35086'
        );
        $this->assertFileDoesNotExist(
            $runtimeDir.'/function_static.c',
            'must not add function_static.c for #35086'
        );
    }
}
