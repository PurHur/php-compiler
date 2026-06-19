<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\JIT;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\DefineRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9410: AOT standalone define/defined must use DefineJitHelper PHP, not phpc_user_constants LLVM global.
 *
 * @group aot-lint
 */
final class DefineRuntimeStandaloneTest extends TestCase
{
    public function testEnsureStandaloneBodiesRegistersDefineJitTableGlobal(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        DefineRuntime::ensureStandaloneBodies($ctx);

        $this->assertNull($ctx->module->getNamedGlobal('phpc_user_constants'));
        $this->assertNotNull($ctx->module->getNamedGlobal('phpc_define_jit_table'));
        $this->assertNotNull($ctx->module->getNamedGlobal('phpc_user_constants_seeded'));
    }
}
