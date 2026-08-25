<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ExceptionBridge remains PHP-only; TypeErrorRaise lazy from ensureMinimal (#34732).
 */
final class ExceptionBridgeRuntimeShrinkTest extends TestCase
{
    public function testExceptionBridgeDelegatesToTypeErrorRaiseAndJitThrow(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ExceptionBridge.php');
        $this->assertStringContainsString('TypeErrorRaise::ensureLinked', $source);
        $this->assertStringContainsString('JitThrow::ensureLinked', $source);
        $this->assertStringContainsString('TypeErrorRaise::ensureStandaloneBodies', $source);
        $this->assertStringContainsString('JitThrow::ensureStandaloneBodies', $source);
    }

    public function testEmitTypeErrorEnsuresBeforeLookup(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ExceptionBridge.php');
        foreach (['emitTypeErrorAndAbort', 'emitArgumentCountErrorAndAbort', 'emitValueErrorAndAbort'] as $method) {
            if (!str_contains($source, 'function '.$method)) {
                continue;
            }
            $pos = strpos($source, 'function '.$method);
            $this->assertNotFalse($pos, $method);
            $next = strpos($source, 'public static function ', $pos + 10);
            $body = false === $next
                ? substr($source, $pos)
                : substr($source, $pos, $next - $pos);
            $this->assertStringContainsString(
                'TypeErrorRaise::ensureLinked',
                $body,
                $method.' must ensureLinked (#34732)'
            );
        }
    }
}
