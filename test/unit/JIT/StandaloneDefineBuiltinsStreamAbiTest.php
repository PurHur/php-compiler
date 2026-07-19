<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StreamLifecycleRuntime;
use PHPCompiler\JIT\Builtin\StreamReadRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Inventory / user-script AOT must link stream lifecycle via NestedJIT (#13137, #20966).
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

    public function testContextDefineBuiltinsRegistersStreamLifecycleNestedJit(): void
    {
        $context = (string) file_get_contents(\dirname(__DIR__, 3).'/lib/JIT/Context.php');
        $this->assertStringContainsString('StreamLifecycleRuntime::ensureLinked', $context);
        $this->assertStringNotContainsString('StreamLifecycleRuntime::ensureDeferredStubsForInventoryEmit', $context);
        $this->assertStringContainsString('StreamReadRuntime::ensureDeferredStubsForInventoryEmit', $context);
    }

    public function testUserScriptAotStreamLifecycleNestedJitLinks(): void
    {
        $prev = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
        $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);

            StreamLifecycleRuntime::ensureLinked($ctx);
            StreamReadRuntime::ensureDeferredStubsForInventoryEmit($ctx);

            foreach ([
                '__compiler_is_resource',
                '__compiler_fflush',
                '__compiler_fclose',
                '__compiler_feof',
                '__compiler_ftell',
                '__compiler_stream_get_contents',
                '__compiler_fseek',
            ] as $name) {
                $fn = $ctx->lookupFunction($name);
                $this->assertNotNull($fn, $name);
                $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
            }
        } finally {
            if (false === $prev || '' === (string) $prev) {
                putenv('PHP_COMPILER_AOT_USER_SCRIPT=');
                unset($_ENV['PHP_COMPILER_AOT_USER_SCRIPT'], $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT']);
            } else {
                putenv('PHP_COMPILER_AOT_USER_SCRIPT='.$prev);
                $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = $prev;
                $_SERVER['PHP_COMPILER_AOT_USER_SCRIPT'] = $prev;
            }
        }
    }
}
