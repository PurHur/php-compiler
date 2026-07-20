<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ValueEchoRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #21513: AOT standalone echo type bridges route through ValueEchoJitHelper via JitVmHelperLink.
 *
 * @group aot-lint
 */
final class ValueEchoRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesTypeBridgesForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        ValueEchoRuntime::implement($ctx);

        foreach ([
            '__value_echo__typeIsNull',
            '__value_echo__typeIsNativeLong',
            '__value_echo__typeIsNativeBool',
            '__value_echo__typeIsNativeDouble',
            '__value_echo__typeIsString',
            '__value_echo__typeIsHashtable',
            '__value_echo__typeIsObject',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
            $this->assertTrue(
                JitVmHelperLink::hasNamedBridgeEntry($fn, 'value_echo_type_bridge_entry'),
                $name.' must use helper bridge entry (#21513)'
            );
        }
    }

    public function testSourceHasNoStandaloneIcmpFork(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/ValueEchoRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('implementStandaloneTypeBridge', $source);
        $this->assertStringNotContainsString('value_echo_type_standalone_entry', $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
    }
}
