<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ErrorBridge remains PHP-only; ErrorRaise lazy from ensureMinimal (#34769).
 */
final class ErrorBridgeRuntimeShrinkTest extends TestCase
{
    public function testErrorBridgeDelegatesToErrorRaiseAndReadonly(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ErrorBridge.php');
        $this->assertStringContainsString('AssertionErrorRaise::ensureLinked', $source);
        $this->assertStringContainsString('ErrorRaise::ensureLinked', $source);
        $this->assertStringContainsString('ReadonlyBridge::ensureLinked', $source);
        $this->assertStringContainsString('ErrorRaise::ensureStandaloneBodies', $source);
        $this->assertStringContainsString('ReadonlyBridge::ensureStandaloneBodies', $source);
    }

    public function testEmitErrorEnsuresBeforeLookup(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ErrorBridge.php');
        $pos = strpos($source, 'function emitError');
        $this->assertNotFalse($pos);
        $next = strpos($source, 'public static function ', $pos + 10);
        $body = false === $next
            ? substr($source, $pos)
            : substr($source, $pos, $next - $pos);
        $this->assertStringContainsString(
            'ErrorRaise::ensureLinked',
            $body,
            'emitError must ensureLinked (#34769)'
        );
    }
}
