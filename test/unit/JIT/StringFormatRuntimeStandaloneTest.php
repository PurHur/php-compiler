<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringFormat;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1492: AOT standalone must define sprintf/printf/number_format runtime in LLVM.
 *
 * @group aot-lint
 */
final class StringFormatRuntimeStandaloneTest extends TestCase
{
    public function testEnsureStandaloneDefinesFormatRuntimeHelpers(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_IMPORT);
        StringFormat::ensureStandaloneBodies($ctx);

        foreach (
            [
                '__compiler_sprintf',
                '__compiler_printf',
                '__compiler_number_format',
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
