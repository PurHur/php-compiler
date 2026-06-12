<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\SessionLifecycleRuntime;
use PHPCompiler\JIT\Builtin\SessionStart;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5332: AOT standalone must define session lifecycle without phpc_session_lifecycle.c.
 *
 * @group aot-lint
 */
final class SessionLifecycleRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesLifecycleForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        SessionLifecycleRuntime::ensureLinked($ctx);

        foreach (
            [
                SessionStart::RUNTIME_C_SYMBOL,
                '__phpc_session_write_close_apply',
                '__phpc_session_regenerate_id_apply',
                '__phpc_session_destroy_apply',
                '__phpc_session_abort_apply',
                '__phpc_session_generate_new_id',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }

    public function testPhpcSessionLifecycleCRemoved(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_session_lifecycle.c');
        $linker = file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertIsString($linker);
        $this->assertStringNotContainsString('phpc_session_lifecycle.c', $linker);
    }
}
