<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureMinimalUserStandaloneBodies always-on ExceptionBridge (#34732 / peer #34695).
 *
 * Thin AOT hello-world must not eagerly NestedJIT TypeErrorRaise / JitThrow ABI;
 * TypeErrorRaise::ensureLinked implements standalone bodies lazily (#32122 .1 mint class).
 */
final class ContextMinimalStandaloneLazyExceptionBridgeRuntimeShrinkTest extends TestCase
{
    public function testEnsureMinimalDropsEagerExceptionBridge(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#34732', $context);
        $minimalPos = strpos($context, 'private function ensureMinimalUserStandaloneBodies');
        $this->assertNotFalse($minimalPos);
        $minimalEnd = strpos($context, 'private function ensureBootstrapAotStandaloneBodies', $minimalPos);
        $this->assertNotFalse($minimalEnd);
        $minimalBody = substr($context, $minimalPos, $minimalEnd - $minimalPos);

        $this->assertStringNotContainsString(
            'ExceptionBridge::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimalUserStandaloneBodies must not eagerly ExceptionBridge (#34732)'
        );

        // CliArgv always-on removed (#34822): compileToFile + CliArgvGlobalInit ensureLinked.
        $this->assertStringNotContainsString(
            'CliArgvRuntime::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimalUserStandaloneBodies must not eagerly CliArgvRuntime (#34822)'
        );
        $this->assertStringNotContainsString(
            'ErrorBridge::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimal must not eagerly ErrorBridge (#34769)'
        );

        // ensureFull still eagerly ExceptionBridge (full AOT fixture surface).
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullEnd = strpos($context, 'private function ', $fullPos + 1);
        $fullHead = false === $fullEnd
            ? substr($context, $fullPos, 2500)
            : substr($context, $fullPos, min(2500, $fullEnd - $fullPos));
        $this->assertStringContainsString(
            'ExceptionBridge::ensureStandaloneBodies($this)',
            $fullHead,
            'ensureFullStandaloneBodies still ensures ExceptionBridge (#34732)'
        );
    }

    public function testTypeErrorRaiseEnsureLinkedImplementsStandaloneBodies(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/TypeErrorRaise.php');
        $this->assertStringContainsString('#34732', $source);
        $pos = strpos($source, 'public static function ensureLinked');
        $this->assertNotFalse($pos);
        $next = strpos($source, 'public static function ensureStandaloneBodies', $pos + 10);
        $this->assertNotFalse($next);
        $body = substr($source, $pos, $next - $pos);
        $this->assertStringContainsString('self::implementBodies($context)', $body);
        $this->assertStringNotContainsString(
            'LOAD_TYPE_STANDALONE !== $context->loadType',
            $body,
            'ensureLinked must implement bodies for STANDALONE too (#34732)'
        );
        $this->assertStringContainsString(
            'BasicBlockHelper::tryGetInsertBlock',
            $source,
            'implementBodies must restore insert block mid-{main} (#34732)'
        );
    }

    public function testEmitClearAndAbortEnsureBeforeLookup(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/TypeErrorRaise.php');
        foreach (['emitClearForStandaloneMain', 'emitAbortIfPendingForStandaloneMain'] as $method) {
            $pos = strpos($source, 'public static function '.$method);
            $this->assertNotFalse($pos, $method);
            $next = strpos($source, 'public static function ', $pos + 10);
            $body = false === $next
                ? substr($source, $pos)
                : substr($source, $pos, $next - $pos);
            $this->assertStringContainsString(
                'self::ensureLinked($context)',
                $body,
                $method.' must ensureLinked before lookup (#34732)'
            );
        }
    }

    public function testNoNewRuntimeCForMinimalExceptionBridgeLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/exception_bridge.c',
            'must not add exception_bridge.c for #34732 — PHP JIT bridges only'
        );
        $this->assertFileDoesNotExist(
            $runtimeDir.'/phpc_type_error_raise.c',
            'must not re-add phpc_type_error_raise.c for #34732'
        );
    }
}
