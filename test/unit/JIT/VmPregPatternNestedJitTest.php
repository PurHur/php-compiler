<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringPregMatch;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * VmPregPattern must nested-JIT compile for PregMatchRuntime prelink (#16075).
 *
 * @group aot-lint
 */
final class VmPregPatternNestedJitTest extends TestCase
{
    public function testVmPregPatternNestedCompileDoesNotSegfault(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        NestedVmHashTableMethodLlvm::ensureMethod($ctx, 'add');
        NestedVmHashTableMethodLlvm::ensureMethod($ctx, 'updateindex');
        NestedVmHashTableMethodLlvm::ensureMethod($ctx, 'append');
        foreach (['null', 'int', 'string', 'array'] as $method) {
            NestedVmVariableMethodLlvm::ensureMethod($ctx, $method);
        }
        NestedVmActiveContextLlvm::ensureMethod($ctx);

        NestedJitCompileScope::run($ctx, static function () use ($ctx, $runtime): void {
            putenv('PHP_COMPILER_AOT_USER_SCRIPT=');
            $jit = new \PHPCompiler\JIT($ctx);
            $path = __DIR__.'/../../../ext/standard/VmPregPattern.php';
            $block = $runtime->parseAndCompile((string) file_get_contents($path), 'VmPregPattern.php');
            self::assertNotNull($block);
            $jit->compile($block);
        });

        StringPregMatch::ensureLinked($ctx);
        $fn = $ctx->lookupFunction('__compiler_preg_match');
        self::assertNotNull($fn);
        self::assertGreaterThan(0, $fn->countBasicBlocks());
    }
}
