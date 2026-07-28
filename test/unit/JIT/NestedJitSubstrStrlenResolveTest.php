<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Func\Internal as FuncInternal;
use PHPCompiler\JIT\Call\ExternalMethod;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * NestedJIT helpers must resolve unqualified substr()/strlen() to Func\Internal (#24217).
 *
 * Pre-registerModule NestedJIT leaves Context->modules empty; NsFuncCall qualifies calls as
 * phpcompiler\ext\standard\substr. Without the NestedJIT string-builtin allowlist those become
 * Call\ExternalMethod silent-null stubs — correct in user code, wrong inside helpers.
 *
 * @group aot-lint
 */
final class NestedJitSubstrStrlenResolveTest extends TestCase
{
    public function testNestedJitHelperResolvesSubstrAndStrlenToInternal(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        foreach (['add', 'addindex', 'updateindex', 'append', 'iterate', 'iteratekeyed', 'exportkeyvaluepairs', 'getnumelements'] as $htMethod) {
            NestedVmHashTableMethodLlvm::ensureMethod($ctx, $htMethod);
        }
        foreach (['null', 'int', 'string', 'array', 'copyfrom', 'resolveindirect'] as $method) {
            NestedVmVariableMethodLlvm::ensureMethod($ctx, $method);
        }
        NestedVmActiveContextLlvm::ensureMethod($ctx);

        $path = __DIR__.'/fixtures/Issue24217SubstrJitHelper.php';
        NestedJitCompileScope::run($ctx, static function () use ($ctx, $runtime, $path): void {
            putenv('PHP_COMPILER_AOT_USER_SCRIPT=');
            putenv('PHP_COMPILER_SELFHOST_AOT=0');
            $jit = new \PHPCompiler\JIT($ctx);
            $block = $runtime->parseAndCompile((string) file_get_contents($path), 'Issue24217SubstrJitHelper.php');
            self::assertNotNull($block);
            $jit->compile($block);
        });

        $logical = 'PHPCompiler\\ext\\standard\\Issue24217SubstrJitHelper::sliceArgv';
        self::assertArrayHasKey(strtolower($logical), $ctx->functions);

        foreach (['substr', 'strlen', 'phpcompiler\\ext\\standard\\substr'] as $name) {
            $proxy = $ctx->resolveFunctionProxy($name);
            self::assertInstanceOf(
                FuncInternal::class,
                $proxy,
                $name.' must not remain Call\\ExternalMethod after NestedJIT (#24217)'
            );
            self::assertNotInstanceOf(ExternalMethod::class, $proxy);
        }

        foreach (array_keys($ctx->externalMethodStubs) as $stub) {
            self::assertStringNotContainsString(
                'substr',
                $stub,
                'substr must not be recorded as an external silent-null stub (#24217)'
            );
            self::assertDoesNotMatchRegularExpression(
                '/(^|\\\\)strlen$/',
                $stub,
                'strlen must not be recorded as an external silent-null stub (#24217)'
            );
        }
    }
}
