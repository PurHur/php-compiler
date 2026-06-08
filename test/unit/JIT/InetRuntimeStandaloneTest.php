<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\InetRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #3225: AOT standalone must define inet conversion LLVM delegates.
 *
 * @group aot-lint
 */
final class InetRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesInetDelegatesForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        InetRuntime::ensureLinked($ctx);
        foreach (['__compiler_ip2long', '__compiler_long2ip', '__compiler_inet_pton', '__compiler_inet_ntop'] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
