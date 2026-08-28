<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type\Object_::register always-on TypeErrorRaise / ErrorRaise NestedJIT (#35638).
 *
 * Peer Context ensureMinimal / ensureFull drops (#34732 / #34769 / #35099). Call sites
 * ExceptionBridge / ErrorBridge / emitRaise / readonly property guards ensureLinked before
 * lookup. Thin hello-world must not NestedJIT pending-Error ABI during Type registration.
 */
final class TypeObjectRegisterLazyErrorBridgeRuntimeShrinkTest extends TestCase
{
    public function testObjectRegisterDropsEagerTypeErrorAndErrorRaiseEnsureLinked(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/Object_.php');
        $this->assertStringContainsString('#35638', $source);
        $pos = strpos($source, 'public function register(): void');
        $this->assertNotFalse($pos);
        $next = strpos($source, 'private function registerFn', $pos);
        $this->assertNotFalse($next);
        $body = substr($source, $pos, $next - $pos);

        $this->assertStringContainsString('TypeErrorRaise::registerDeclarations', $body);
        $this->assertStringContainsString('ErrorRaise::registerDeclarations', $body);
        $this->assertStringNotContainsString(
            'TypeErrorRaise::ensureLinked($this->context)',
            $body,
            'Object_::register must not eagerly ensureLinked TypeErrorRaise (#35638)'
        );
        $this->assertStringNotContainsString(
            'ErrorRaise::ensureLinked($this->context)',
            $body,
            'Object_::register must not eagerly ensureLinked ErrorRaise (#35638)'
        );
    }

    public function testExceptionBridgeStillEnsuresBeforeEmit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ExceptionBridge.php');
        $this->assertStringContainsString('TypeErrorRaise::ensureLinked', $source);
    }

    public function testErrorBridgeStillEnsuresBeforeEmit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ErrorBridge.php');
        $this->assertStringContainsString('ErrorRaise::ensureLinked', $source);
    }

    public function testNoNewRuntimeCForPendingErrorAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_type_error_raise.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_error_raise.c');
    }
}
