<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\JIT;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\RewriteVarsRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9753: AOT standalone output rewrite vars must use OutputRewriteVarsJitHelper PHP, not phpc_rewrite_vars LLVM global.
 *
 * @group aot-lint
 */
final class RewriteVarsRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedCompilesOutputRewriteVarsJitHelperForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        RewriteVarsRuntime::ensureLinked($ctx);

        foreach ([
            'phpcompiler\\ext\\standard\\outputrewritevarsjithelper::add',
            'phpcompiler\\ext\\standard\\outputrewritevarsjithelper::reset',
        ] as $name) {
            $this->assertArrayHasKey($name, $ctx->functions);
        }

        $this->assertNull($ctx->module->getNamedGlobal('phpc_rewrite_vars'));
    }
}
