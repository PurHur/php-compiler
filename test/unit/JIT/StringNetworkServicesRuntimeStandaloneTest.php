<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringNetworkServices;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #6218 / #9777: network service helpers must link for standalone AOT.
 *
 * @group aot-lint
 */
final class StringNetworkServicesRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesNetworkServiceHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringNetworkServices::ensureLinked($ctx);
        StringNetworkServices::ensureStringReturnLinked($ctx);

        foreach ([
            '__compiler_getprotobynumber',
            '__compiler_getservbyport',
            '__phpc_getprotobyname',
            '__phpc_getservbyname',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }

    public function testRuntimeShrinkRemovesNetworkServicesC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_network_services.c');
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_network_services.c', $linker);
    }

    public function testStringReturnBridgeUsesPhpHelperEntryBlock(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringNetworkServicesStringReturn.php');
        $this->assertStringContainsString('getprotobynumber_bridge_entry', $source);
        $this->assertStringNotContainsString('ns_proto_num_match_', $source);
    }
}
