<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on ob_* ABI shells from Builtin\Type (#33798 / #33862).
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

    public function testTypeRegisterDropsAlwaysOnObOutputDeclareAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33862', $type);
        $this->assertStringNotContainsString(
            'ObOutputRuntime::declareObAbis($this->context)',
            $type,
            'Builtin\\Type::register must not eagerly declare ob_* empty shells (#33862)'
        );
        $this->assertStringNotContainsString(
            'ObOutput::registerExternals($this->context)',
            $type,
            'Builtin\\Type must not call ObOutput::registerExternals (#33862)'
        );
    }

    public function testRuntimeOwnerDeclaresObAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ObOutputRuntime.php');
        $this->assertStringContainsString('#33862', $owner);
        $this->assertStringContainsString('declareObAbis', $owner);
        $this->assertStringContainsString('self::declareObAbis($context)', $owner);
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ObOutputJitBridge.php');
        $this->assertStringContainsString('getNamedFunction', $bridge);
        $this->assertStringContainsString('__phpc_ob_start', $bridge);
        $embed = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/EmbedObOutput.php');
        $this->assertStringContainsString('ObOutputRuntime::declareObAbis($context)', $embed);
        $storage = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ObStorageLlvm.php');
        $this->assertStringContainsString('#33862', $storage);
        $this->assertMatchesRegularExpression(
            '/ensureGzhandlerFlushStub\(\$context\);.*implementObEndClean/s',
            $storage,
            'ObStorageLlvm must stub gzhandler flush before end/flush bodies (#33862)'
        );
    }

    public function testContextDropsAlwaysOnObOutputForMinimalStandalone(): void
    {
        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#34695', $ctx);
        $minimalPos = strpos($ctx, 'private function ensureMinimalUserStandaloneBodies');
        $this->assertNotFalse($minimalPos);
        $minimalEnd = strpos($ctx, 'private function ensureBootstrapAotStandaloneBodies', $minimalPos);
        $this->assertNotFalse($minimalEnd);
        $minimalBody = substr($ctx, $minimalPos, $minimalEnd - $minimalPos);
        $this->assertStringNotContainsString(
            'ObOutputRuntime::ensureLinked($this)',
            $minimalBody,
            'ensureMinimal must not eagerly ObOutput (#34695 / peer #33862)'
        );
    }

    public function testNoRuntimeCForObOutputAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_stream.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/ob_output.c');
    }
}
