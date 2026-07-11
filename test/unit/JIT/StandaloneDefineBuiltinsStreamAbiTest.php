<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StreamLifecycleRuntime;
use PHPCompiler\JIT\Builtin\StreamReadRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Inventory argv emit must link stream ABI stubs before nested helper JIT (#13137).
 *
 * @group aot-lint
 */
final class StandaloneDefineBuiltinsStreamAbiTest extends TestCase
{
    protected function setUp(): void
    {
        if (\function_exists('putenv')) {
            putenv('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1');
            putenv('BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1');
        }
    }

    public function testContextDefineBuiltinsRegistersDeferredStreamAbi(): void
    {
        $context = (string) file_get_contents(\dirname(__DIR__, 3).'/lib/JIT/Context.php');
        $this->assertStringContainsString('StreamLifecycleRuntime::ensureDeferredStubsForInventoryEmit', $context);
        $this->assertStringContainsString('StreamReadRuntime::ensureDeferredStubsForInventoryEmit', $context);
    }

    public function testInventoryEmitDeferredStreamAbiStubsLink(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);

        StreamLifecycleRuntime::ensureDeferredStubsForInventoryEmit($ctx);
        StreamReadRuntime::ensureDeferredStubsForInventoryEmit($ctx);

        foreach ([
            '__compiler_is_resource',
            '__compiler_fflush',
            '__compiler_ftell',
            '__compiler_stream_get_contents',
            '__compiler_fseek',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
