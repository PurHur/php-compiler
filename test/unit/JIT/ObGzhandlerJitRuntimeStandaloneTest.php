<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ObGzhandlerJitRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9091: ob_gzhandler JIT routes through ObGzhandlerJitHelper PHP, not LLVM handler body.
 *
 * @group aot-lint
 */
final class ObGzhandlerJitRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesObGzhandlerForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        ObGzhandlerJitRuntime::implement($ctx);

        foreach (['__compiler_ob_gzhandler', '__phpc_ob_gzhandler_flush', '__phpc_ob_start_with_gzhandler'] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
