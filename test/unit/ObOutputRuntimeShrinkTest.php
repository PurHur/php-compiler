<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on ob_* ABI shells from Builtin\Type (#33798).
 *
 * NestedJIT/AOT bridge stays ObOutputRuntime / ObOutputJitBridge
 * (php-src ext/standard/output.c). Runtime owner declares module-locally
 * (getNamedFunction first) so leftover Type empty decls cannot mint
 * ob_start.1 (#31894 / #32122).
 */
final class ObOutputRuntimeShrinkTest extends TestCase
{
    public function testTypeInitializeDropsAlwaysOnObOutputExternals(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33798', $type);
        $this->assertStringNotContainsString(
            'ObOutput::registerExternals($this->context)',
            $type,
            'Builtin\\Type::initialize must not eagerly register ob_* empty shells (#33798)'
        );
        $this->assertStringContainsString('ObOutputRuntime::declareObAbis($this->context)', $type);
        foreach ([
            '__phpc_ob_start',
            '__phpc_ob_echo_cstr',
            '__phpc_ob_get_level',
            '__phpc_headers_sent',
        ] as $abi) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($abi, '/').'[\'"]/',
                $type,
                'Builtin\\Type must not always-declare '.$abi.' (#33798)'
            );
        }
    }

    public function testRuntimeOwnerDeclaresObAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ObOutputRuntime.php');
        $this->assertStringContainsString('#33798', $owner);
        $this->assertStringContainsString('declareObAbis', $owner);
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ObOutputJitBridge.php');
        $this->assertStringContainsString('getNamedFunction', $bridge);
        $this->assertStringContainsString('__phpc_ob_start', $bridge);
    }

    public function testContextStillEnsureLinksObOutputForStandalone(): void
    {
        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('ObOutputRuntime::ensureLinked($this)', $ctx);
    }

    public function testNoRuntimeCForObOutputAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_stream.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/ob_output.c');
    }
}
