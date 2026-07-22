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
 * Issue #21965: NestedJIT must lower VmUrlRewriterOb under PHP_COMPILER_EMIT_HELPER_LINK=1.
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
            'phpcompiler\\ext\\standard\\vmurlrewriterob::ensureregistered',
            'phpcompiler\\ext\\standard\\vmurlrewriterob::resetstate',
            'phpcompiler\\ext\\standard\\outputrewritevarsjithelper::add',
            'phpcompiler\\ext\\standard\\outputrewritevarsjithelper::reset',
        ] as $name) {
            $this->assertArrayHasKey($name, $ctx->functions);
        }

        $this->assertNull($ctx->module->getNamedGlobal('phpc_rewrite_vars'));
    }

    public function testEnsureLinkedUnderEmitHelperLinkRegistersUrlRewriterMethods(): void
    {
        $prev = \getenv('PHP_COMPILER_EMIT_HELPER_LINK');
        \putenv('PHP_COMPILER_EMIT_HELPER_LINK=1');
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
            $void = $ctx->context->voidType();
            $ft = $ctx->context->functionType($void, false);
            $fn = $ctx->module->addFunction('__test_rewrite_vars_caller', $ft);
            $entry = $fn->appendBasicBlock('entry');
            $ctx->builder->positionAtEnd($entry);
            $ctx->main = $fn;
            $ctx->activeFunction = '__test_rewrite_vars_caller';
            $ctx->registerFunction('__test_rewrite_vars_caller', $fn);

            RewriteVarsRuntime::ensureLinked($ctx);
            $this->assertArrayHasKey(
                'phpcompiler\\ext\\standard\\vmurlrewriterob::ensureregistered',
                $ctx->functions
            );
            $this->assertArrayHasKey(
                'phpcompiler\\ext\\standard\\outputrewritevarsjithelper::add',
                $ctx->functions
            );
            // Must not throw LogicException("Current basic block has no parent function") (#21972).
            JIT\BasicBlockHelper::parentFunction($ctx);
            $this->assertNotNull(JIT\BasicBlockHelper::append($ctx, 'after_rewrite_vars'));
        } finally {
            if (false === $prev || '' === (string) $prev) {
                \putenv('PHP_COMPILER_EMIT_HELPER_LINK=');
            } else {
                \putenv('PHP_COMPILER_EMIT_HELPER_LINK='.$prev);
            }
        }
    }
}
