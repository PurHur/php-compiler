<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ArrayReduceRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * ArrayReduceJitHelper NestedJIT must lower Variable::null on helper-scoped temps (#24117).
 *
 * @group aot-lint
 */
final class ArrayReduceJitHelperNestedJitTest extends TestCase
{
    public function testArrayReduceJitHelperNestedCompileAcceptsNull(): void
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

        NestedJitCompileScope::run($ctx, static function () use ($ctx, $runtime): void {
            putenv('PHP_COMPILER_AOT_USER_SCRIPT=');
            $jit = new \PHPCompiler\JIT($ctx);
            $path = __DIR__.'/../../../ext/standard/ArrayReduceJitHelper.php';
            $block = $runtime->parseAndCompile((string) file_get_contents($path), 'ArrayReduceJitHelper.php');
            self::assertNotNull($block);
            $jit->compile($block);
        });

        ArrayReduceRuntime::ensureLinked($ctx);
        $fn = $ctx->lookupFunction('__array_reduce__builtin');
        self::assertNotNull($fn);
        self::assertGreaterThan(0, $fn->countBasicBlocks());
    }
}
