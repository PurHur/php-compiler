<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureMinimalUserStandaloneBodies always-on ErrorBridge (#34769 / peer #34732).
 *
 * Thin AOT hello-world must not eagerly NestedJIT ErrorRaise / AssertionErrorRaise /
 * ReadonlyRaise ABI; call-site ensureLinked implements standalone bodies (#32122 .1 mint class).
 */
final class ContextMinimalStandaloneLazyErrorBridgeRuntimeShrinkTest extends TestCase
{
    public function testEnsureMinimalDropsEagerErrorBridge(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#34769', $context);
        $minimalPos = strpos($context, 'private function ensureMinimalUserStandaloneBodies');
        $this->assertNotFalse($minimalPos);
        $minimalEnd = strpos($context, 'private function ensureBootstrapAotStandaloneBodies', $minimalPos);
        $this->assertNotFalse($minimalEnd);
        $minimalBody = substr($context, $minimalPos, $minimalEnd - $minimalPos);

        $this->assertStringNotContainsString(
            'ErrorBridge::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimalUserStandaloneBodies must not eagerly ErrorBridge (#34769)'
        );

        // CliArgv always-on removed (#34822): compileToFile + CliArgvGlobalInit ensureLinked.
        $this->assertStringNotContainsString(
            'CliArgvRuntime::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimalUserStandaloneBodies must not eagerly CliArgvRuntime (#34822)'
        );

        // ensureFull still eagerly ErrorBridge (full AOT fixture surface).
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullEnd = strpos($context, 'private function ', $fullPos + 1);
        $fullHead = false === $fullEnd
            ? substr($context, $fullPos, 2500)
            : substr($context, $fullPos, min(2500, $fullEnd - $fullPos));
        $this->assertStringContainsString(
            'ErrorBridge::ensureStandaloneBodies($this)',
            $fullHead,
            'ensureFullStandaloneBodies still ensures ErrorBridge (#34769)'
        );
    }

    public function testErrorRaiseEnsureLinkedImplementsStandaloneBodies(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ErrorRaise.php');
        $this->assertStringContainsString('#34769', $source);
        $pos = strpos($source, 'public static function ensureLinked');
        $this->assertNotFalse($pos);
        $next = strpos($source, 'public static function ensureStandaloneBodies', $pos + 10);
        $this->assertNotFalse($next);
        $body = substr($source, $pos, $next - $pos);
        $this->assertStringContainsString('self::implementBodies($context)', $body);
        $this->assertStringNotContainsString(
            'LOAD_TYPE_STANDALONE !== $context->loadType',
            $body,
            'ensureLinked must implement bodies for STANDALONE too (#34769)'
        );
        $this->assertStringContainsString(
            'BasicBlockHelper::tryGetInsertBlock',
            $source,
            'implementBodies must restore insert block mid-{main} (#34769)'
        );
    }

    public function testAssertionErrorRaiseEnsureLinkedImplementsStandaloneBodies(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/AssertionErrorRaise.php');
        $this->assertStringContainsString('#34769', $source);
        $pos = strpos($source, 'public static function ensureLinked');
        $this->assertNotFalse($pos);
        $next = strpos($source, 'public static function ensureStandaloneBodies', $pos + 10);
        $this->assertNotFalse($next);
        $body = substr($source, $pos, $next - $pos);
        $this->assertStringContainsString('self::implementBodies($context)', $body);
        $this->assertStringNotContainsString(
            'LOAD_TYPE_STANDALONE !== $context->loadType',
            $body,
            'ensureLinked must implement bodies for STANDALONE too (#34769)'
        );
        $this->assertStringContainsString(
            'BasicBlockHelper::tryGetInsertBlock',
            $source,
            'implementBodies must restore insert block mid-{main} (#34769)'
        );
    }

    public function testEmitClearAndAbortEnsureBeforeLookup(): void
    {
        foreach ([
            __DIR__.'/../../lib/JIT/Builtin/ErrorRaise.php',
            __DIR__.'/../../lib/JIT/Builtin/ReadonlyRaise.php',
        ] as $path) {
            $source = (string) file_get_contents($path);
            foreach (['emitClearForStandaloneMain', 'emitAbortIfPendingForStandaloneMain'] as $method) {
                $pos = strpos($source, 'public static function '.$method);
                $this->assertNotFalse($pos, $path.' '.$method);
                $next = strpos($source, 'public static function ', $pos + 10);
                $body = false === $next
                    ? substr($source, $pos)
                    : substr($source, $pos, $next - $pos);
                $this->assertStringContainsString(
                    'self::ensureLinked($context)',
                    $body,
                    basename($path).'::'.$method.' must ensureLinked before lookup (#34769)'
                );
            }
        }
    }

    public function testEmitRaiseEnsuresBeforeLookup(): void
    {
        foreach ([
            __DIR__.'/../../lib/JIT/Builtin/ErrorRaise.php',
            __DIR__.'/../../lib/JIT/Builtin/AssertionErrorRaise.php',
        ] as $path) {
            $source = (string) file_get_contents($path);
            $pos = strpos($source, 'public static function emitRaise');
            $this->assertNotFalse($pos, $path);
            $next = strpos($source, 'public static function ', $pos + 10);
            $body = false === $next
                ? substr($source, $pos)
                : substr($source, $pos, $next - $pos);
            $this->assertStringContainsString(
                'self::ensureLinked($context)',
                $body,
                basename($path).'::emitRaise must ensureLinked (#34769)'
            );
        }
    }

    public function testNoNewRuntimeCForMinimalErrorBridgeLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/error_bridge.c',
            'must not add error_bridge.c for #34769 — PHP JIT bridges only'
        );
        $this->assertFileDoesNotExist(
            $runtimeDir.'/phpc_error_raise.c',
            'must not re-add phpc_error_raise.c for #34769'
        );
    }
}
