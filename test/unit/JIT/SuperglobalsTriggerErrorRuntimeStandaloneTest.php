<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringTriggerError;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #7597: AOT standalone must define trigger-error runtime in LLVM, not superglobals_refresh.c.
 *
 * @group aot-lint
 */
final class SuperglobalsTriggerErrorRuntimeStandaloneTest extends TestCase
{
    public function testEnsureStandaloneDefinesTriggerErrorRuntimeHelper(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringTriggerError::ensureStandaloneBodies($ctx);

        foreach (
            [
                '__phpc_stderr_print_cli_error',
                '__compiler_undefined_array_key_warning_cstr',
                '__compiler_undefined_array_key_warning_long',
                '__compiler_trigger_error',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name.' must be linked for standalone AOT');
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name.' must have LLVM body');
        }
    }

    public function testSuperglobalsRefreshCFileRemoved(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/superglobals_refresh.c');
    }
}
