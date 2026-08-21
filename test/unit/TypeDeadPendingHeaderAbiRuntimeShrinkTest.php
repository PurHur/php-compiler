<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on pending-header ABI shells from Builtin\Type (#33255).
 *
 * NestedJIT/AOT bridge stays PendingHeadersRuntime / PendingHeadersJitBridge
 * (php-src ext/standard/head.c). Runtime owner declares module-locally
 * (getNamedFunction first) so leftover Type empty decls cannot mint
 * pending_header_*.1 (#31894 / #32122).
 */
final class TypeDeadPendingHeaderAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnPendingHeaderAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33255', $type);
        foreach ([
            '__phpc_pending_header_reset',
            '__phpc_pending_header_add',
            '__phpc_pending_header_remove',
            '__phpc_pending_header_list',
            '__phpc_header_queue_enable',
            '__phpc_response_headers_flush',
            '__phpc_setcookie_add',
        ] as $abi) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($abi, '/').'[\'"]/',
                $type,
                'Builtin\\Type must not always-declare '.$abi.' (#33255)'
            );
            $this->assertStringNotContainsString(
                "registerFunction('".$abi."'",
                $type,
                'Builtin\\Type must not always-register '.$abi.' (#33255)'
            );
        }
        // No further Type always-on leftover after #33267 exit/abort drop.
        $this->assertStringContainsString('PendingHeadersRuntime::declarePendingHeaderAbis', $type);
        $this->assertStringContainsString('PendingHeadersRuntime::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresPendingHeaderAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PendingHeadersRuntime.php');
        $this->assertStringContainsString('#33255', $owner);
        $this->assertStringContainsString('declarePendingHeaderAbis', $owner);
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PendingHeadersJitBridge.php');
        $this->assertStringContainsString('#33255', $bridge);
        $this->assertStringContainsString('declarePendingHeaderAbis', $bridge);
        $this->assertStringContainsString('getNamedFunction', $bridge);
        $this->assertStringContainsString('__phpc_pending_header_reset', $bridge);
    }

    public function testTypeInitializeStillEnsureLinksPendingHeaders(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('PendingHeadersRuntime::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForPendingHeaderAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/pending_header.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/pending_header.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_pending_header.c');
    }
}
