<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureMinimalUserStandaloneBodies always-on Stream* (#34836 / peer #34822 / #34439).
 *
 * Bootstrap-aot + user-script thin init must not NestedJIT StreamLifecycle/StreamRead/StreamBucket
 * during ensureMinimal — call sites already ensureLinked (#32122 .1 mint class).
 */
final class ContextMinimalStandaloneLazyStreamRuntimeShrinkTest extends TestCase
{
    public function testEnsureMinimalDropsEagerStreamRuntime(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#34836', $context);
        $minimalPos = strpos($context, 'private function ensureMinimalUserStandaloneBodies');
        $this->assertNotFalse($minimalPos);
        $minimalEnd = strpos($context, 'private function ensureBootstrapAotStandaloneBodies', $minimalPos);
        $this->assertNotFalse($minimalEnd);
        $minimalBody = substr($context, $minimalPos, $minimalEnd - $minimalPos);

        $this->assertStringNotContainsString(
            'StreamLifecycleRuntime::ensureLinked($this)',
            $minimalBody,
            'ensureMinimalUserStandaloneBodies must not eagerly StreamLifecycleRuntime (#34836)'
        );
        $this->assertStringNotContainsString(
            'StreamReadRuntime::ensureLinked($this)',
            $minimalBody,
            'ensureMinimalUserStandaloneBodies must not eagerly StreamReadRuntime (#34836)'
        );
        $this->assertStringNotContainsString(
            'StreamBucket::ensureLinked($this)',
            $minimalBody,
            'ensureMinimalUserStandaloneBodies must not eagerly StreamBucket (#34836)'
        );
        // Peer keep: CliArgv always-on already removed (#34822).
        $this->assertStringNotContainsString(
            'CliArgvRuntime::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimal must not eagerly CliArgvRuntime (#34822)'
        );
    }

    public function testCallSitesAndFullStandaloneStillEnsure(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString(
            'StreamLifecycleRuntime::ensureLinked($this)',
            $context,
            'ensureFullStandaloneBodies still ensureLinked StreamLifecycle (#34836)'
        );
        $this->assertStringContainsString(
            'StreamReadRuntime::ensureLinked($this)',
            $context,
            'ensureFullStandaloneBodies still ensureLinked StreamRead (#34836)'
        );

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
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must ensure lazily (#34836)');
        }
    }

    public function testNoNewRuntimeCForMinimalStreamLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/stream_lifecycle.c',
            'must not add stream_lifecycle.c for #34836 — PHP JIT bridges only'
        );
        $this->assertFileDoesNotExist(
            $runtimeDir.'/stream_bucket.c',
            'must not add stream_bucket.c for #34836'
        );
    }
}
