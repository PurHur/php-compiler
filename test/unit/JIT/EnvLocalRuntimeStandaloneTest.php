<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\EnvLocalRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5345 / #9814: AOT standalone must define env local helpers without phpc_env_local.c.
 *
 * @group aot-lint
 */
final class EnvLocalRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesEnvLocalForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        EnvLocalRuntime::ensureLinked($ctx);

        foreach (
            [
                '__compiler_env_local_lookup',
                '__compiler_env_register_putenv',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }

        $this->assertNotNull($ctx->module->getNamedGlobal('phpc_env_local_entries'));
        $this->assertNotNull($ctx->module->getNamedGlobal('phpc_env_local_count'));
    }

    public function testPhpcEnvLocalCRuntimeRemovedFromLinker(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $linker = file_get_contents($repoRoot.'/lib/AOT/Linker.php');
        $this->assertIsString($linker);
        $this->assertStringNotContainsString('phpc_env_local.c', $linker);
        $this->assertFileDoesNotExist($repoRoot.'/lib/AOT/runtime/phpc_env_local.c');
    }
}
